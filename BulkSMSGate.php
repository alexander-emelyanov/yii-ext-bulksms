<?php

/**
 * Class BulkSMSGate
 */

class BulkSMSGate extends CComponent
{

    /**
     * Username, provided by bulksms.com
     * @var string
     */
    public $username = '';

    /**
     * Password, provided by bulksms.com
     * @var string
     */
    public $password = '';

    /**
     * @var string
     */
    public $url = 'http://bulksms.vsms.net/eapi/submission/send_sms/2/2.0';

    /**
     * We recommend that you use port 5567 instead of port 80, but your
     * firewall will probably block access to this port (see FAQ on http://bulksms.com for more details):
     * $var int
     */
    public $port = 5567;


    public function init()
    {

    }

    /**
     * @param $to string The phone number, including country code, i.e. +44123123123
     * @param $message
     * @return mixed $result
     */
    public function send($to, $message)
    {

        $to = static::prepareTo($to);

        $body = static::unicodeSMS($this->username, $this->password, $message, $to);
        $result = static::sendMessage($body, $this->url, $this->port);

        Yii::log("Message [$message] sent to [$to] with result: [" . print_r($result, 1) . "]", CLogger::LEVEL_INFO, 'application.external.sms.bulksms');

        return $result;
    }

    /**
     * Re
     * @param $to
     * @return string
     */
    protected static function prepareTo($to)
    {
        return preg_replace("/[^0-9,.]/", "", $to);
    }

    protected function sendMessage($post_body, $url, $port)
    {

        /*
         * Do not supply $post_fields directly as an argument to CURLOPT_POSTFIELDS,
         * despite what the PHP documentation suggests: cUrl will turn it into in a
         * multi-part form post, which is not supported:
        */

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PORT, $port);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
        // Allowing cUrl funtions 20 second to execute
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        // Waiting 20 seconds while trying to connect
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);

        $response_string = curl_exec($ch);
        $curl_info = curl_getinfo($ch);

        $sms_result = array();
        $sms_result['success'] = 0;
        $sms_result['details'] = '';
        $sms_result['transient_error'] = 0;
        $sms_result['http_status_code'] = $curl_info['http_code'];
        $sms_result['api_status_code'] = '';
        $sms_result['api_message'] = '';
        $sms_result['api_batch_id'] = '';

        if ($response_string == FALSE) {
            $sms_result['details'] .= "cURL error: " . curl_error($ch) . "\n";
        } elseif ($curl_info['http_code'] != 200) {
            $sms_result['transient_error'] = 1;
            $sms_result['details'] .= "Error: non-200 HTTP status code: " . $curl_info['http_code'] . "\n";
        } else {
            $sms_result['details'] .= "Response from server: $response_string\n";
            $api_result = explode('|', $response_string);
            $status_code = $api_result[0];
            $sms_result['api_status_code'] = $status_code;
            $sms_result['api_message'] = $api_result[1];
            if (count($api_result) != 3) {
                $sms_result['details'] .= "Error: could not parse valid return data from server.\n" . count($api_result);
            } else {
                if ($status_code == '0') {
                    $sms_result['success'] = 1;
                    $sms_result['api_batch_id'] = $api_result[2];
                    $sms_result['details'] .= "Message sent - batch ID $api_result[2]\n";
                } else if ($status_code == '1') {
                    # Success: scheduled for later sending.
                    $sms_result['success'] = 1;
                    $sms_result['api_batch_id'] = $api_result[2];
                } else {
                    $sms_result['details'] .= "Error sending: status code [$api_result[0]] description [$api_result[1]]\n";
                }


            }
        }
        curl_close($ch);

        return $sms_result;
    }

    protected static function unicodeSMS($username, $password, $message, $to)
    {
        $postFields = [
            'username' => $username,
            'password' => $password,
            'message' => static::stringToUTF16Hex($message),
            'msisdn' => $to,
            'dca' => '16bit'
        ];
        return static::makePostBody($postFields);

    }

    protected static function makePostBody($post_fields)
    {
        $stop_dup_id = static::makeStopDupId();
        if ($stop_dup_id > 0) {
            $post_fields['stop_dup_id'] = static::makeStopDupId();
        }
        $post_body = '';
        foreach ($post_fields as $key => $value) {
            $post_body .= urlencode($key) . '=' . urlencode($value) . '&';
        }
        $post_body = rtrim($post_body, '&');

        return $post_body;
    }

    /**
     * @return int
     * Unique ID to eliminate duplicates in case of network timeouts - see
     * EAPI documentation for more. You may want to use a database primary
     * key. Warning: sending two different messages with the same
     * ID will result in the second being ignored!
     *
     * Don't use a timestamp - for instance, your application may be able
     * to generate multiple messages with the same ID within a second, or
     * part thereof.
     *
     * You can't simply use an incrementing counter, if there's a chance that
     * the counter will be reset.
     */
    protected static function makeStopDupId()
    {
        return 0;
    }

    /**
     * @param $string
     * @return string
     */
    protected static function stringToUTF16Hex($string)
    {
        return bin2hex(mb_convert_encoding($string, "UTF-16", "UTF-8"));
    }

}