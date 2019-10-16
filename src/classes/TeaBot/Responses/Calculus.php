<?php

namespace TeaBot\Responses;

use stdClass;
use TeaBot\Exe;
use TeaBot\Data;
use TeaBot\Lang;
use TeaBot\ResponseFoundation;
use TeaBot\Plugins\Tex2Png\Tex2Png;

/**
 * @author Ammar Faizi <ammarfaizi2@gmail.com> https://www.facebook.com/ammarfaizi2
 * @license MIT
 * @package \TeaBot
 * @version 6.2.0
 */
final class Calculus extends ResponseFoundation
{
	/**
	 * @param \TeaBot\Data &$data
	 *
	 * Constructor.
	 */
	public function __construct(Data &$data)
	{
		parent::__construct($data);
		loadConfig("calculus");
		define("DEFAULT_CALCULUS_HEADERS",
			[
				"X-Requested-With: XMLHttpRequest",
				"Authorization: Bearer ".(CALCULUS_API_KEY)
			]
		);
	}

	/**
	 * @param string $expression
	 * @return bool
	 */
	public function simple(string $expression): bool
	{
		$res = $this->exec($expression);
		if (!$res) goto ret;

		if (isset($res["solutions"][0]["entire_result"])) {
			Exe::sendMessage(
				[
					"chat_id" => $this->data["chat_id"],
					"reply_to_message_id" => $this->data["msg_id"],
					"text" => $res["dym"]["originalEquation"].$res["solutions"][0]["entire_result"]
				]
			);
		}

		ret:
		return true;
	}

	/**
	 * @param string $expression
	 * @return bool
	 */
	public function simpleImg(string $expression): bool
	{
		$res = $this->exec($expression);
		if (!$res) goto ret;

		if (isset($res["solutions"][0]["entire_result"])) {

			$rr = $res["solutions"][0]["entire_result"];
			if ($rr[0] === "=") {
				$r = $res["dym"]["originalEquation"].$rr;
			} else {
				$r = "(".$res["dym"]["originalEquation"].") = (".$rr.")";
			}

			$o = json_decode(self::curl("https://api.teainside.org/latex.php?exp=".urlencode($r))["out"], true);
			if (isset($o["error"])) {
				Exe::sendMessage(
					[
						"chat_id" => $this->data["chat_id"],
						"reply_to_message_id" => $this->data["msg_id"],
						"text" => "Latex Error Occured:\n<code>".htmlspecialchars($o["error"], ENT_QUOTES, "UTF-8"),
						"parse_mode" => "html"
					]
				);
			} else {
				Exe::sendPhoto(
					[
						"chat_id" => $this->data["chat_id"],
						"reply_to_message_id" => $this->data["msg_id"],
						"photo" => $o["ret"],
						"caption" => "<pre>".htmlspecialchars($r, ENT_QUOTES, "UTF-8")."</pre>",
						"parse_mode" => "html"
					]
				);
			}
		}

		ret:
		return true;
	}

	/**
	 * @param string $expression
	 * @return ?array
	 */
	public function exec(string $expression): ?array
	{
		$ret = null;

		$expression = trim($expression);
		$hash = sha1($expression);
		$cacheFile = CALCULUS_STORAGE_PATH."/cache/".$hash;

		if (file_exists($cacheFile)) {
			$res = json_decode(file_get_contents($cacheFile), true);
			if (isset($res["solutions"])) {
				$ret = $res;
				goto ret;
			}
		}

		$expression = urlencode($expression);
		$o = self::curl("https://www.symbolab.com/pub_api/steps?userId=fe&query={$expression}&language=en&subscribed=false&plotRequest=PlotOptional");

		// Curl error.
		if ($o["err"]) {
			Exe::sendMessage(
				[
					"chat_id" => $this->data["chat_id"],
					"reply_to_message_id" => $this->data["msg_id"],
					"text" => "An error occured: {$o["ern"]}: {$o["err"]}"
				]
			);
			$ret = null;
			goto ret;
		}

		$res = json_decode($o["out"], true);
		if (isset($res["solutions"])) {
			$ret = $res;
			file_put_contents($cacheFile, $o["out"]);
		} else {
			$ret = null;
			Exe::sendMessage(
				[
					"chat_id" => $this->data["chat_id"],
					"reply_to_message_id" => $this->data["msg_id"],
					"text" => "Couldn't get the result"
				]
			);
		}

		ret:
		return $ret;
	}

	/**
	 * @param string $url
	 * @param array  $opt
	 * @return array
	 */
	public static function curl(string $url, array $opt = []): array
	{
		$ch = curl_init($url);
		$optf = [
			CURLOPT_HTTPHEADER => DEFAULT_CALCULUS_HEADERS,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false
		];
		foreach ($opt as $k => $v) {
			$optf[$k] = $v;
		}
		curl_setopt_array($ch, $optf);
		$o = curl_exec($ch);
		$err = curl_error($ch);
		$ern = curl_errno($ch);
		return [
			"out" => $o,
			"err" => $err,
			"ern" => $ern
		];
	}
}