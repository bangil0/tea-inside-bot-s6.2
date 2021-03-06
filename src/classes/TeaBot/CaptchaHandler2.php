<?php

namespace TeaBot;

/**
 * @author Ammar Faizi <ammarfaizi2@gmail.com> https://www.facebook.com/ammarfaizi2
 * @license MIT
 * @package \TeaBot
 * @version 6.2.0
 */
final class CaptchaHandler2
{
    const CAPTCHA_DIR = "/tmp/telegram/captcha_handler";

    /**
     * @var \TeaBot\Data
     */
    public $data;

    /**
     * @var string
     */
    public $type;

    /**
     * @var array
     */
    public $welcomeMessages = [];

    /**
     * @param \TeaBot\Data $data
     * @param string       $type
     * @param array        $welcomeMessage
     *
     * Constructor.
     */
    public function __construct(Data $data, string $type, array $welcomeMessages)
    {
        $this->data = $data;
        $this->type = $type;
        $this->welcomeMessages = $welcomeMessages;
        is_dir(self::CAPTCHA_DIR) or mkdir(self::CAPTCHA_DIR);
    }

    /**
     * @param \TeaBot\Data $data
     * @return bool
     */
    public static function havingCaptcha(Data $data): bool
    {
        if (isset($data["text"]) && file_exists($f = self::CAPTCHA_DIR.
            "/{$data["chat_id"]}/{$data["user_id"]}")) {

            $handle = fopen($f, "r+");
            flock($handle, LOCK_EX);
            $str = fgets($handle);
            $d = json_decode(str_replace("\0", "", $str), true);

            if (trim($data["text"]) === (string)$d["cdata"]["correct_answer"]) {
                unlink($f);
                $o = Exe::sendMessage(
                    [
                        "chat_id" => $data["chat_id"],
                        "reply_to_message_id" => $data["msg_id"],
                        "text" => $d["mention"].
                            " has answered the captcha correctly, welcome to the group!",
                        "parse_mode" => "HTML"
                    ]
                )["out"];
                $o = json_decode($o, true);
                self::socketDispatch(
                    [
                        "answer_okx" => $d["tid"],
                        "type" => $d["type"],
                        "ok_msg_id" => $o["result"]["message_id"],
                        "c_answer_id" => $data["msg_id"]
                    ]
                );
            } else {

                if (($data["date"] - $d["date"]) <= 2) {
                    $d["spam"]++;
                }
                $d["date"] = $data["date"];
                $d["cycle"]++;

                ftruncate($handle, strlen($str));
                rewind($handle);
                fwrite($handle, json_encode($d, JSON_UNESCAPED_SLASHES));
                fclose($handle);

                if ($d["spam"] >= 8) {
                    if (!file_exists($f.".kicked")) {
                        touch($f.".kicked");
                        Exe::kickChatMember(
                            [
                                "chat_id" => $data["chat_id"],
                                "user_id" => $data["user_id"]
                            ]
                        );
                        sleep(5);
                        self::socketDispatch(
                            [
                                "answer_okx" => $d["tid"],
                                "type" => $d["type"],
                                "ok_msg_id" => $d["captcha_msg_id"],
                                "c_answer_id" => $d["join_msg_id"],
                                "cancel_sleep" => 15
                            ]
                        );
                        $unban = true;
                    }
                    Exe::deleteMessage(
                        [
                            "chat_id" => $data["chat_id"],
                            "message_id" => $data["msg_id"]
                        ]
                    );
                    if (isset($unban)) {
                        sleep(30);
                        Exe::unbanChatMember(
                            [
                                "chat_id" => $data["chat_id"],
                                "user_id" => $data["user_id"]
                            ]
                        );
                        file_exists($f.".kicked") and unlink($f.".kicked");
                    }
                    return true;
                }

                $o = Exe::sendMessage(
                    [
                        "chat_id" => $data["chat_id"],
                        "reply_to_message_id" => $data["msg_id"],
                        "text" => "Wrong answer!"
                    ]
                )["out"];
                $o = json_decode($o, true);
                $d = json_decode(file_get_contents($f, LOCK_EX), true);
                if ($d["spam"] >= 8) {
                    Exe::deleteMessage(
                        [
                            "chat_id" => $data["chat_id"],
                            "message_id" => $o["result"]["message_id"]
                        ]
                    );
                    Exe::deleteMessage(
                        [
                            "chat_id" => $data["chat_id"],
                            "message_id" => $data["msg_id"]
                        ]
                    );
                } else {
                    file_put_contents(
                        self::CAPTCHA_DIR.
                        "/{$data["chat_id"]}/delete_msg_queue/{$data["user_id"]}/{$o["result"]["message_id"]}",
                        time());
                    file_put_contents(
                        self::CAPTCHA_DIR.
                        "/{$data["chat_id"]}/delete_msg_queue/{$data["user_id"]}/{$data["msg_id"]}",
                        time());
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        switch ($this->type) {
            case "calculus2":
                $this->calculusCaptcha();
                break;
            
            default:
                break;
        }
    }

    /**
     * @return void
     */
    private function calculusCaptcha()
    {
        is_dir(self::CAPTCHA_DIR."/{$this->data["chat_id"]}") or
                mkdir(self::CAPTCHA_DIR."/{$this->data["chat_id"]}");

        is_dir(self::CAPTCHA_DIR."/{$this->data["chat_id"]}/delete_msg_queue") or
                mkdir(self::CAPTCHA_DIR."/{$this->data["chat_id"]}/delete_msg_queue");

        foreach ($this->data["new_chat_members"] as $v) {

            if (file_exists($f = self::CAPTCHA_DIR.
                "/{$this->data["chat_id"]}/{$v["id"]}")) {
                $d = json_decode(file_get_contents($f, LOCK_EX), true);
                self::socketDispatch(
                    [
                        "answer_okx" => $d["tid"],
                        "type" => $d["type"],
                        "ok_msg_id" => $d["captcha_msg_id"],
                        "c_answer_id" => $d["join_msg_id"],
                        "cancel_sleep" => 0
                    ]
                );
            }

            is_dir(self::CAPTCHA_DIR.
                "/{$this->data["chat_id"]}/delete_msg_queue/{$v["id"]}") or
                mkdir(self::CAPTCHA_DIR.
                    "/{$this->data["chat_id"]}/delete_msg_queue/{$v["id"]}");

            $handle = fopen(
                self::CAPTCHA_DIR."/{$this->data["chat_id"]}/{$v["id"]}",
                "w+");
            flock($handle, LOCK_EX);

            $sockData = [];
            $cdata = json_decode(file_get_contents("https://captcha.teainside.org/api.php?key=abc123&action=get_captcha&type=calculus"), true);

            $name = htmlspecialchars($v["first_name"].
                (isset($v["last_name"]) ? " ".$v["last_name"] : ""),
                ENT_QUOTES, "UTF-8");

            $mention = "<a href=\"tg://user?id={$v["id"]}\">{$name}</a>";
            $cdata["photo"] = "https://api.teainside.org/latex_x.php?border=200&d=400&exp="
                .urlencode($cdata["latex"]);

            if (isset($v["username"])) {
                $mention .= " (@".$v["username"].")";
            }

            $minutes = $cdata["est_time"] / 60;
            $cdata["tg_msg"] = $mention.
                "\n<b>Please solve this captcha problem to make sure you are a human or you will be kicked in {$minutes} minutes.</b>\n\n".$cdata["msg"];

            $sockData["banned_hash"] = md5($cdata["correct_answer"]);

            file_put_contents(
                "/tmp/telegram/calculus_lock/".$sockData["banned_hash"],
                time());

            $sockData["captcha_msg_id"] = json_decode(Exe::sendPhoto(
                [
                    "chat_id" => $this->data["chat_id"],
                    "reply_to_message_id" => $this->data["msg_id"],
                    "caption" => $cdata["tg_msg"],
                    "photo" => $cdata["photo"],
                    "parse_mode" => "HTML"
                ]
            )["out"], true)["result"]["message_id"];

            $sockData["type"] = "calculus";
            $sockData["sleep"] = $cdata["est_time"];
            $sockData["user_id"] = $v["id"];
            $sockData["chat_id"] = $this->data["chat_id"];
            $sockData["join_msg_id"] = $this->data["msg_id"];
            $sockData["welcome_msg_id"] = $this->welcomeMessages[$v["id"]] ?? -1;
            $sockData["mention"] = $mention;
            $sockData["tid"] = self::socketDispatch($sockData);
            $sockData["cdata"] = $cdata;
            $sockData["cycle"] = 0;
            $sockData["spam"] = 0;
            $sockData["date"] = 0;

            fwrite($handle, json_encode($sockData, JSON_UNESCAPED_SLASHES));
            fclose($handle);
        }
    }

    /**
     * @param array $sockData
     * @return int
     */
    private static function socketDispatch(array $sockData): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $json = json_encode($sockData, JSON_UNESCAPED_SLASHES);
        socket_connect($socket, "127.0.0.1", 10001);
        socket_send($socket, sprintf("%07d", $len = strlen($json)), 7, 0);
        socket_send($socket, $json, $len, 0);
        socket_recv($socket, $buf, 100, 0);
        socket_close($socket);
        return (int)$buf;
    }
}
