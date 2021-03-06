
PHP_ARG_ENABLE(teabot,
  [Whether to enable the "teabot" extension],
  [  --enable-teabot        Enable "teabot" extension support])

if test $PHP_TEABOT != "no"; then
  PHP_SUBST(SAMPLE_SHARED_LIBADD)
  PHP_NEW_EXTENSION(teabot, teabot.c classes/TeaBot_Lang.c classes/TeaBot_CaptchaThread.c, $ext_shared,, "-Wall -lpthread -lcurl")
fi
