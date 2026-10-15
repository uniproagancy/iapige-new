<?php

return [

    /*
     | smsoffice.ge, the gateway the shop sends its text messages through.
     |
     | The key and the sender name are issued per account and the sender name
     | has to be registered with them before it will send — an unregistered
     | one is refused at the gateway, not here, so a message that silently
     | fails to arrive is usually this.
     */
    'url' => env('SMSOFFICE_URL', 'https://smsoffice.ge/api/v2/send/'),
    'api_key' => env('SMSOFFICE_KEY'),
    'sender' => env('SMSOFFICE_SENDER'),

    /*
     | The switch.
     |
     | Off by default, because a staging copy of the database carries real
     | telephone numbers and the gateway charges for every one of them. It is
     | turned on in production's .env and nowhere else.
     */
    'enabled' => (bool) env('SMSOFFICE_ENABLED', false),

    /*
     | How long to wait for the gateway, in seconds.
     |
     | Short on purpose: a text message is sent while somebody is waiting for
     | a page, so a gateway having a bad afternoon must not hold the checkout
     | open. The message is worth less than the request it is part of.
     */
    'timeout' => (int) env('SMSOFFICE_TIMEOUT', 10),

    /*
     | Where every message goes instead, when this is set.
     |
     | For testing against the real gateway without texting customers: the
     | content and the gateway's answer are real, only the recipient is not.
     */
    'redirect_to' => env('SMSOFFICE_REDIRECT_TO'),

];
