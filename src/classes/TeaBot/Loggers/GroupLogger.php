<?php

namespace TeaBot\Loggers;

use DB;
use PDO;
use TeaBot\Exe;
use TeaBot\LoggerFoundation;
use TeaBot\Contracts\LoggerInterface;

/**
 * @author Ammar Faizi <ammarfaizi2@gmail.com> https://www.facebook.com/ammarfaizi2
 * @license MIT
 * @package \TeaBot\Loggers
 * @version 6.2.0
 */
final class GroupLogger extends LoggerFoundation implements LoggerInterface
{
	/**
	 * @return void
	 */
	public function run(): void
	{
		$this->saveGroupInfo();
		$this->saveUserInfo();
	}

	/**
	 * @return void
	 */
	public function saveGroupInfo(): void
	{
		$createHistory = false;
		$st = $this->pdo->prepare("SELECT `name`, `username`, `link`, `photo` FROM `groups` WHERE `group_id` = :group_id LIMIT 1;");
		$st->execute([":group_id" => $this->data["chat_id"]]);
		$data = [
			":group_id" => $this->data["chat_id"],
			":name" => $this->data["group_name"],
			":username" => $this->data["group_username"],
			":link" => null,
			":photo" => null,
			":created_at" => date("Y-m-d H:i:s")
		];

		if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
			if (
				($this->data["group_name"] !== $r["name"]) ||
				($this->data["group_username"] !== $r["username"])
			) {
				$createHistory = true;
				$this->pdo->prepare("UPDATE `groups` SET `username` = :username, `name` = :name, `msg_count` = `msg_count` + 1, `updated_at` = :updated_at WHERE `group_id` = :group_id LIMIT 1;")->execute(
					[
						":name" => $this->data["group_name"],
						":username" => $this->data["group_username"],
						":updated_at" => $data[":created_at"],
						":group_id" => $this->data["chat_id"]
					]
				);
			} else {
				$this->pdo->prepare("UPDATE `groups` SET `msg_count` = `msg_count` + 1, `updated_at` = :updated_at WHERE `group_id` = :group_id LIMIT 1;")->execute(
					[
						":updated_at" => $data[":created_at"],
						":group_id" => $this->data["chat_id"]
					]
				);
			}
		} else {
			$this->pdo->prepare("INSERT INTO `groups` (`group_id`, `name`, `username`, `link`, `photo`, `msg_count`, `created_at`) VALUES (:group_id, :name, :username, :link, :photo, 1, :created_at);")->execute($data);
			$createHistory = true;
		}

		if ($createHistory) {
			$this->pdo->prepare("INSERT INTO `groups_history` (`group_id`, `name`, `username`, `link`, `photo`, `created_at`) VALUES (:group_id, :name, :username, :link, :photo, :created_at);")->execute($data);
		}
	}

	/**
	 * @return void
	 */
	public function saveUserInfo(): void
	{
		$createHistory = false;
		$st = $this->pdo->prepare("SELECT `username`, `first_name`, `last_name`, `photo` FROM `users` WHERE `user_id` = :user_id LIMIT 1;");
		$st->execute([":user_id" => $this->data["user_id"]]);
		$data = [
			":user_id" => $this->data["user_id"],
			":username" => $this->data["username"],
			":first_name" => $this->data["first_name"],
			":last_name" => $this->data["last_name"],
			":photo" => null,
			":created_at" => date("Y-m-d H:i:s")
		];

		if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
			if (
				($this->data["username"] !== $r["username"]) ||
				($this->data["first_name"] !== $r["first_name"]) ||
				($this->data["last_name"] !== $r["last_name"])
			) {
				$createHistory = true;
				$this->pdo->prepare("UPDATE `users` SET `username` = :username, `first_name` = :first_name, `last_name` = :last_name, `group_msg_count` = `group_msg_count` + 1, `updated_at` = :updated_at WHERE `user_id` = :user_id LIMIT 1;")->execute(
					[
						":username" => $this->data["username"],
						":first_name" => $this->data["first_name"],
						":last_name" => $this->data["last_name"],
						":updated_at" => $data[":created_at"],
						":user_id" => $data[":user_id"]
					]
				);
			} else {
				$this->pdo->prepare("UPDATE `users` SET `group_msg_count` = `group_msg_count` + 1, `updated_at` = :updated_at WHERE `user_id` = :user_id LIMIT 1;")->execute(
					[
						":updated_at" => $data[":created_at"],
						":user_id" => $data[":user_id"]
					]
				);
			}
		} else {
			$data[":is_bot"] = ($this->data["is_bot"] ? '1' : '0');
			$this->pdo->prepare("INSERT INTO `users` (`user_id`, `username`, `first_name`, `last_name`, `photo`, `is_bot`, `group_msg_count`, `private_msg_count`, `created_at`, `updated_at`) VALUES (:user_id, :username, :first_name, :last_name, :photo, :is_bot, 1, 0, :created_at, NULL);")->execute($data);
			unset($data[":is_bot"]);
			$createHistory = true;
		}

		if ($createHistory) {
			$this->pdo->prepare("INSERT INTO `users_history` (`user_id`, `username`, `first_name`, `last_name`, `photo`, `created_at`) VALUES (:user_id, :username, :first_name, :last_name, :photo, :created_at);")->execute($data);
		}
	}

	/**
	 * @return void
	 */
	public function logText(): void
	{
		$this->pdo
			->prepare("INSERT INTO `groups_messages` (`group_id`, `user_id`, `tmsg_id`, `reply_to_tmsg_id`, `msg_type`, `text`, `text_entities`, `file`, `is_edited`, `tmsg_datetime`, `created_at`) VALUES (:group_id, :user_id, :tmsg_id, :reply_to_tmsg_id, :msg_type, :text, :text_entities, NULL, :is_edited, :tmsg_datetime, :created_at);")
			->execute(
				[
					":group_id" => $this->data["chat_id"],
					":user_id" => $this->data["user_id"],
					":tmsg_id" => $this->data["msg_id"],
					":reply_to_tmsg_id" => (
						isset($this->data["reply"]) ? $this->data["reply"]["message_id"] : null
					),
					":msg_type" => "text",
					":text" => $this->data["text"],
					":text_entities" => (
						isset($this->data["entities"]) ? json_encode($this->data["entities"], JSON_UNESCAPED_SLASHES) : null
					),
					":is_edited" => '0',
					":tmsg_datetime" => date("Y-m-d H:i:s", $this->data["date"]),
					":created_at" => date("Y-m-d H:i:s")
				]
			);
	}

	/**
	 * @return void
	 */
	public function logPhoto(): void
	{
		$photo = end($this->data["photo"]);
		$o = json_decode(Exe::getFile(["file_id" => $photo["file_id"]])["out"], true);
		if (isset($o["file_path"])) {
			$o = Exe::($o["file_path"])()["out"];
			print $o;
		}
	}
}
