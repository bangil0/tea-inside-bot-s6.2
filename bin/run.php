<?php

require __DIR__."/../config/global.php";
require __DIR__."/../bootstrap/autoload.php";


$json = '{
    "update_id": 345237741,
    "message": {
        "message_id": 22283,
        "from": {
            "id": 243692601,
            "is_bot": false,
            "first_name": "Ammar",
            "last_name": "Faizi",
            "username": "ammarfaizi2",
            "language_code": "en"
        },
        "chat": {
            "id": -1001128970273,
            "title": "Private Cloud",
            "type": "supergroup"
        },
        "date": 1555600051,
        "text": "/debug",
        "entities": [
            {
                "offset": 0,
                "length": 6,
                "type": "bot_command"
            }
        ]
    }
}';

$st = new \TeaBot\TeaBot($json);
$st->run();