
#include <stdio.h>
#include <dirent.h>
#include <stdlib.h>
#include <stdint.h>
#include <unistd.h>
#include <string.h>
#include <pthread.h>
#include <stdbool.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <curl/curl.h>

#include "../teabot.h"

/**
 * @package \TeaBot
 * @author Ammar Faizi <ammarfaizi2@gmail.com>
 * @license MIT
 */

extern zend_class_entry *teabot_captchathread_ce;

#define DEBUG_CT 1
#if DEBUG_CT
    #define debug_print(...) printf(__VA_ARGS__)
#else
    #define debug_print(...)
#endif
#define MAX_QUEUE 500

typedef struct {
    pthread_t thread;
    bool busy;
    bool cancel;
    char *type;
    size_t type_len;
    zend_long sleep_time;
    zend_long user_id;
    char *chat_id;
    size_t chat_id_len;
    zend_long join_msg_id;
    zend_long captcha_msg_id;
    zend_long welcome_msg_id;
    zend_long ok_msg_id;
    zend_long c_answer_id;
    zend_long cancel_sleep;
    char *banned_hash;
    size_t banned_hash_len;
    char *mention;
    size_t mention_len;
} captcha_queue;

typedef struct {
    char *data;
    size_t len;
    size_t allocated;
} tgcurl_res;

static const unsigned char hexchars[] = "0123456789ABCDEF";
static char *token, *captcha_dir;
static size_t token_len, captcha_dir_len;

static uint16_t qpos = 0;
static captcha_queue queues[MAX_QUEUE];

static void clear_del_queue(captcha_queue *qw);
static tgcurl_res tgExe(char *method, char *payload);
static void *calculus_queue_dispatch(captcha_queue *qw);
static unsigned char *teabot_urlencode(const char *s, size_t len);


/**
 * TeaBot\CaptchaThread::__construct
 */
PHP_METHOD(TeaBot_CaptchaThread, __construct)
{
    char *_token, *_captcha_dir;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(_token, token_len)
        Z_PARAM_STRING(_captcha_dir, captcha_dir_len)
    ZEND_PARSE_PARAMETERS_END();

    token = (char *)malloc(token_len + 1);
    captcha_dir = (char *)malloc(captcha_dir_len + 1);

    memcpy(token, _token, token_len);
    memcpy(captcha_dir, _captcha_dir, captcha_dir_len);

    token[token_len] = captcha_dir[captcha_dir_len] = '\0';

    zend_update_property_stringl(teabot_captchathread_ce, getThis(), ZEND_STRL("token"),
        token, token_len TSRMLS_CC);

    zend_update_property_stringl(teabot_captchathread_ce, getThis(), ZEND_STRL("captcha_dir"),
        captcha_dir, captcha_dir_len TSRMLS_CC);
}

/**
 * TeaBot\CaptchaThread::dispatch
 */
PHP_METHOD(TeaBot_CaptchaThread, dispatch)
{
    char *chat_id, *banned_hash, *mention;
    register void* (*handler)(void *) = NULL;
    register uint16_t cpos = qpos++;

    ZEND_PARSE_PARAMETERS_START(9, 9)
        Z_PARAM_STRING(queues[cpos].type, queues[cpos].type_len)
        Z_PARAM_LONG(queues[cpos].sleep_time)
        Z_PARAM_LONG(queues[cpos].user_id)
        Z_PARAM_STRING(chat_id, queues[cpos].chat_id_len)
        Z_PARAM_LONG(queues[cpos].join_msg_id)
        Z_PARAM_LONG(queues[cpos].captcha_msg_id)
        Z_PARAM_LONG(queues[cpos].welcome_msg_id)
        Z_PARAM_STRING(banned_hash, queues[cpos].banned_hash_len)
        Z_PARAM_STRING(mention, queues[cpos].mention_len)
    ZEND_PARSE_PARAMETERS_END();

    queues[cpos].chat_id = (char *)malloc(queues[cpos].chat_id_len + 1);
    queues[cpos].banned_hash = (char *)malloc(queues[cpos].banned_hash_len + 1);
    queues[cpos].mention = (char *)malloc(queues[cpos].mention_len + 1);

    memcpy(queues[cpos].chat_id, chat_id, queues[cpos].chat_id_len);
    memcpy(queues[cpos].banned_hash, banned_hash, queues[cpos].banned_hash_len);
    memcpy(queues[cpos].mention, mention, queues[cpos].mention_len);

    queues[cpos].chat_id[queues[cpos].chat_id_len] =
    queues[cpos].banned_hash[queues[cpos].banned_hash_len] =
    queues[cpos].mention[queues[cpos].mention_len] = '\0';

    if (!strcmp(queues[cpos].type, "calculus")) {
        handler = (void* (*)(void *))calculus_queue_dispatch;
    }

    if (handler) {
        queues[cpos].busy = true;
        queues[cpos].cancel = false;
        qpos = qpos % MAX_QUEUE;
        pthread_create(&(queues[cpos].thread), NULL,
            (void* (*)(void *))handler, (void *)&(queues[cpos]));
        pthread_detach(queues[cpos].thread);
        RETURN_LONG(cpos)
    } else {
        RETURN_LONG(-1)
    }
}


/**
 * TeaBot\CaptchaThread::run
 */
PHP_METHOD(TeaBot_CaptchaThread, run)
{
}


/**
 * TeaBot\CaptchaThread::cancel
 */
PHP_METHOD(TeaBot_CaptchaThread, cancel)
{
    zend_long index, ok_msg_id, c_answer_id, cancel_sleep;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(index)
        Z_PARAM_LONG(ok_msg_id)
        Z_PARAM_LONG(c_answer_id)
        Z_PARAM_LONG(cancel_sleep)
    ZEND_PARSE_PARAMETERS_END();

    if ((index >= 0) && (index < MAX_QUEUE)) {
        queues[index].c_answer_id = c_answer_id;
        queues[index].ok_msg_id = ok_msg_id;
        queues[index].cancel_sleep = cancel_sleep;
        queues[index].cancel = true;
    }
}


static void *calculus_queue_dispatch(captcha_queue *qw)
{
    char *ptrx, *ptry, kick_msg_id[64], fdc[256],
        tmp[2048], payload[sizeof(tmp) + 4096];
    tgcurl_res res;
    register unsigned char *ectmp;

    debug_print("qw->type = %s\n", qw->type);
    debug_print("qw->sleep_time = %d\n", (int)qw->sleep_time);
    debug_print("qw->user_id = %d\n", (int)qw->user_id);
    debug_print("qw->chat_id = %s\n", qw->chat_id);
    debug_print("qw->join_msg_id = %d\n", (int)qw->join_msg_id);
    debug_print("qw->captcha_msg_id = %d\n", (int)qw->captcha_msg_id);
    debug_print("qw->welcome_msg_id = %d\n", (int)qw->welcome_msg_id);
    debug_print("qw->banned_hash = %s\n", qw->banned_hash);
    debug_print("qw->mention = %s\n", qw->mention);

    debug_print("Sleeping for %d...\n", (int)qw->sleep_time);

    if (qw->sleep_time < 0) {
        qw->sleep_time *= -1;
    }

    while (qw->sleep_time--) {
        sleep(1);
        if (qw->cancel) break;
    }

    debug_print("Sleep done!\n");

    if (!qw->cancel) {

        sprintf(fdc, "%s/%s/%d", captcha_dir, qw->chat_id, (int)qw->user_id);
        debug_print("Checking fdc file %s...\n", fdc);

        if (access(fdc, F_OK) == -1) {
            debug_print("File does not exist\n");
            goto ret;
        } else {
            debug_print("File exists\n");
        }


        // Kick user from the group.
        sprintf(payload, "chat_id=%s&user_id=%d",
            qw->chat_id, (int)qw->user_id);
        res = tgExe("kickChatMember", payload);
        debug_print("kickChatMember: %s\n", res.data);
        free(res.data);


        // // Delete fdc file.
        // unlink(fdc);


        // Send kick messgae.
        sprintf(tmp,
            "%s has been kicked from the group due to failed to answer the captcha.",
            qw->mention);
        ectmp = teabot_urlencode(tmp, strlen(tmp));
        sprintf(payload, "chat_id=%s&parse_mode=HTML&text=%s",
            qw->chat_id, ectmp);
        res = tgExe("sendMessage", payload);
        debug_print("Kick message: %s\n", res.data);
        free(ectmp);


        // Get kick message id.
        if (!(ptrx = strstr(res.data, "message_id\":"))) goto ret_clr_res;
        ptrx += 12;
        if (!(ptry = strstr(ptrx, ",")))  goto ret_clr_res;
        *ptry = '\0';
        strcpy(kick_msg_id, ptrx);
        free(res.data);

        // Delete unused messages.
        clear_del_queue(qw);
        sleep(2);
        clear_del_queue(qw);

        // Delete welcome message.
        if (((int)qw->welcome_msg_id) != -1) {
            sprintf(payload,"chat_id=%s&message_id=%d",
                qw->chat_id, (int)qw->welcome_msg_id);
            res = tgExe("deleteMessage", payload);

            debug_print("delete_message: %s\n", res.data);

            free(res.data);
        }

        // Delete captcha.
        sprintf(payload, "chat_id=%s&message_id=%d",
            qw->chat_id, (int)qw->captcha_msg_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete captcha msg: %s\n", res.data);
        free(res.data);

        // Unban user.
        sprintf(payload, "chat_id=%s&user_id=%d",
            qw->chat_id, (int)qw->user_id);
        res = tgExe("unbanChatMember", payload);
        debug_print("unban user: %s\n", res.data);
        free(res.data);


        sleep(60);

        // Delete join message.
        sprintf(payload, "chat_id=%s&message_id=%d",
            qw->chat_id, (int)qw->join_msg_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete join message: %s\n", res.data);
        free(res.data);

        // Delete kick message.
        sprintf(payload, "chat_id=%s&message_id=%s",
            qw->chat_id, kick_msg_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete kick message: %s\n", res.data);
        free(res.data);

        goto ret;

        ret_clr_res:
        free(res.data);

    } else {
        debug_print("Job cancelled!\n");

        // Delete unused messages.
        clear_del_queue(qw);

        // Delete captcha.
        sprintf(payload, "chat_id=%s&message_id=%d",
            qw->chat_id, (int)qw->captcha_msg_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete captcha msg: %s\n", res.data);
        free(res.data);

        sleep(qw->cancel_sleep);

        // Delete welcome message.
        if (((int)qw->welcome_msg_id) != -1) {
            sprintf(payload,"chat_id=%s&message_id=%d",
                qw->chat_id, (int)qw->welcome_msg_id);
            res = tgExe("deleteMessage", payload);

            debug_print("delete_message: %s\n", res.data);

            free(res.data);
        }

        // Delete c_answer_id
        sprintf(payload, "chat_id=%s&message_id=%d",
            qw->chat_id, (int)qw->c_answer_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete c_answer_id: %s\n", res.data);
        free(res.data);

        // Delete ok_msg_id
        sprintf(payload, "chat_id=%s&message_id=%d",
            qw->chat_id, (int)qw->ok_msg_id);
        res = tgExe("deleteMessage", payload);
        debug_print("delete ok_msg_id: %s\n", res.data);
        free(res.data);
    }


ret:
    if (qw->banned_hash) {
        sprintf(fdc, "/tmp/telegram/calculus_lock/%s", qw->banned_hash);
        debug_print("Deleting calculus banned hash %s...\n", fdc);
        unlink(fdc);
    }

    free(qw->chat_id);
    free(qw->banned_hash);
    free(qw->mention);

    qw->busy = false;

    return NULL;
}


static bool isinum(char *str)
{
    bool ret = true;

    while (*str) {
        if (((*str) < '0') || ((*str) > '9')) {
            ret = false;
            break;
        }
        str++;
    }

    return ret;
}


struct del_msg_qw
{
    bool busy;
    pthread_t thread;
    struct dirent *file;
    char *dir;
    captcha_queue *qw;
};

static void *del_exmsg(void *ptr)
{
    #define ww ((struct del_msg_qw *)ptr)

    tgcurl_res res;
    char payload[1024];

    sprintf(payload, "chat_id=%s&message_id=%s",
        ww->qw->chat_id, ww->file->d_name);
    res = tgExe("deleteMessage", payload);

    debug_print("delete_message: %s\n", res.data);

    sprintf(payload, "%s/%s", ww->dir, ww->file->d_name);
    debug_print("deleting %s\n", payload);
    unlink(payload);

    free(res.data);
    free(ww->file);

    ww->busy = false;

    return NULL;
    #undef ww
}


static void clear_del_queue(captcha_queue *qw)
{
    #define del_thread_amt 3

    bool got_ch;
    int i, n;
    char cmpt[2048], delMsgDir[1024];
    struct del_msg_qw qww[del_thread_amt];
    struct dirent **namelist;

    sprintf(delMsgDir, "%s/%s/delete_msg_queue/%d",
        captcha_dir, qw->chat_id, (int)qw->user_id);

    debug_print("Scanning msg queue: %s\n", delMsgDir);
    
    memset(&qww, 0, sizeof(qww));

    n = scandir(delMsgDir, &namelist, NULL, alphasort);
    if (n == -1) return;

    while (n--) {
        if ((n > 1) && (isinum(namelist[n]->d_name))) {

            got_ch = false;
            while (!got_ch) {
                for (i = 0; i < del_thread_amt; ++i) {
                    if (!qww[i].busy) {
                        qww[i].dir = delMsgDir;
                        qww[i].file = namelist[n];
                        qww[i].qw = qw;
                        qww[i].busy = true;
                        got_ch = true;
                        break;
                    }
                }
                usleep(10000);
            }

            // printf("%s\n", namelist[n]->d_name);

            pthread_create(&(qww[i].thread), NULL,
                (void * (*)(void *))del_exmsg, (void *)&(qww[i]));
            pthread_detach(qww[i].thread);

        } else {

            sprintf(cmpt, "%s/%s", delMsgDir, namelist[n]->d_name);
            unlink(cmpt);

            free(namelist[n]);
        }
    }

    while (true) {
        got_ch = false;
        for (i = 0; i < del_thread_amt; ++i) {
            if (qww[i].busy) {
                got_ch = true;
                break;
            }
        }
        if (!got_ch) break;
        usleep(10000);
    }

    free(namelist);

    #undef del_thread_amt
}


static size_t internalTgExeWrite(void *content, size_t sz, size_t nmemb, void *ctx)
{
    register tgcurl_res *res = (tgcurl_res *)ctx;
    register size_t op = res->len, rsize = sz * nmemb;

    res->len += rsize;
    if ((res->len + 2048) >= res->allocated) {
        res->data = (char *)realloc(res->data,
            res->allocated + 2048 + rsize);
        res->allocated += 2048 + rsize;
    }

    memcpy(&(res->data[op]), content, rsize);
    return rsize;
}


static tgcurl_res tgExe(char *method, char *payload)
{
    register CURL *curl;
    CURLcode ret;
    char url[1024];
    tgcurl_res res;

    curl = curl_easy_init();
    if (curl) {

        res.len = 0;
        res.allocated = 8096;
        res.data = (char *)malloc(res.allocated);

        sprintf(url, "https://api.telegram.org/bot%s/%s", token, method);

        curl_easy_setopt(curl, CURLOPT_URL, url);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, internalTgExeWrite);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &res);
        curl_easy_setopt(curl, CURLOPT_POSTFIELDS, payload);
        curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)strlen(payload));
        
        ret = curl_easy_perform(curl);

        if (ret != CURLE_OK) {
            printf("curl_easy_perform() failed: %s\n",
                curl_easy_strerror(ret));
        }

        curl_easy_cleanup(curl);
    } else {
        memset(&res, 0, sizeof(res));
        printf("Error: cannot initialize curl thread\n");
    }

    res.data[res.len] = 0;
    return res;
}


static unsigned char *teabot_urlencode(const char *s, size_t len)
{
    register unsigned char c;
    unsigned char *to, *start;
    unsigned char const *from, *end;

    if (len == 0) {
        len = strlen(s);
    }

    from = (unsigned char *)s;
    end = (unsigned char *)s + len;
    to = (unsigned char *)malloc((len * 3) + 1);
    start = to;

    while (from < end) {
        c = *from++;
        if (c == ' ') {
            *to++ = '+';
#ifndef CHARSET_EBCDIC
        } else if ((c < '0' && c != '-' && c != '.') ||
                   (c < 'A' && c > '9') ||
                   (c > 'Z' && c < 'a' && c != '_') ||
                   (c > 'z')) {
            to[0] = '%';
            to[1] = hexchars[c >> 4];
            to[2] = hexchars[c & 15];
            to += 3;
#else /*CHARSET_EBCDIC*/
        } else if (!isalnum(c) && strchr("_-.", c) == NULL) {
            /* Allow only alphanumeric chars and '_', '-', '.'; escape the rest */
            to[0] = '%';
            to[1] = hexchars[os_toascii[c] >> 4];
            to[2] = hexchars[os_toascii[c] & 15];
            to += 3;
#endif /*CHARSET_EBCDIC*/
        } else {
            *to++ = c;
        }
    }
    *to = '\0';

    return start;
}
