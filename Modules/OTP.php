<?php

namespace App\Http\Modules;
use GuzzleHttp\Client;
use stdClass;

use App\Models\OtpLogs;
use App\Models\Users;

use App\Http\Modules\LineNoti;

class SMSMKT{
    public function __construct(){
        $this->api_key = config('app.sms_api_key');
        $this->secret_key = config('app.sms_secret_key');
        $this->sender = config('app.sms_sender');

        $this->api_request = new Client([
            'base_uri' => 'https://portal-otp.smsmkt.com/api/',
            'timeout'  => 30.0,
            'headers' => [
                'Content-Type' => 'application/json',
                "api_key" => $this->api_key,
                "secret_key" => $this->secret_key
            ],
        ]);
    }

    public function requestOTP($body){
        $result = new stdClass;

        if($body && $body->phone){
            $otp_request = $this->api_request->post('otp-send', [
                'json' => [
                    'project_key' => config('app.sms_project_key'),
                    'phone' => $body->phone
                ]
            ]);

            $statusCode = $otp_request->getStatusCode();
            $sms_response = json_decode($otp_request->getBody()->getContents(), true);

            $handleStatus = $this->handleStatus($sms_response['code']);

            if(isset($handleStatus) && !empty($handleStatus) && $handleStatus->status && $sms_response['code'] == "000"){
                //store sms to log
                $log                = new OtpLogs;
                $log->phone         = $body->phone;
                $log->type          = 'success';
                $log->code          = $sms_response['code'];
                $log->detail        = json_encode($sms_response['result']);
                $log->save();

                //response
                $result->status     = true;
                $result->otp_token  = $sms_response['result']['token'];
                $result->otp_ref    = $sms_response['result']['ref_code'];
            }else{
                //store sms to log
                $log                = new Otplogs;
                $log->phone         = $body->phone;
                $log->type          = 'error';
                $log->code          = $sms_response['code'];
                $log->detail        = $sms_response['detail'];
                $log->save();

                $result->status     = false;
                $result->message    = 'request otp error. (Ref: ' . $log->id . ')';

                //send line noti
                try{
                    $message = 'Request OTP error : ' . "\n"
                            . 'phone : ' . $body->phone . "\n"
                            . 'error code : ' . $sms_response['code'] . "\n"
                            . 'detail : ' . $sms_response['detail'] . "\n"
                            . 'log id : ' . $log->id;

                    $line           = new LineNoti;
                    $line->sendMessage($message);
                }catch(Exception $e){

                }
            }
        }else{
            $result->status = false;
            $result->message = 'Phone number not found.';
        }

        return $result;
    }

    public function confirmOTP($body){
        $result = new stdClass;

        if($body && $body->token){
            $otp_confirm = $this->api_request->post('otp-validate', [
                'json' => [
                    'token' => $body->token,
                    'otp_code' => $body->otp_input
                ]
            ]);

            $sms_response = json_decode($otp_confirm->getBody()->getContents(), true);
            $handleStatus = $this->handleStatus($sms_response['code']);

            if(isset($handleStatus) && !empty($handleStatus) && $handleStatus->status && $sms_response['code'] == "000"){

                if($sms_response['result']['status']){
                    //store sms to log
                    $log                = new OtpLogs;
                    $log->phone         = $body->phone;
                    $log->type          = 'success';
                    $log->code          = $sms_response['code'];
                    $log->detail        = 'Confirm success | ' . json_encode($sms_response['detail']);
                    $log->save();

                    //response
                    $result->status     = true;
                    $result->otp_valid  = true;
                }else{
                    //store sms to log
                    $log                = new Otplogs;
                    $log->phone         = $body->phone;
                    $log->type          = 'error';
                    $log->code          = $sms_response['code'];
                    $log->detail        = 'otp invalid';
                    $log->save();

                    $result->status     = true;
                    $result->otp_valid  = false;
                    $result->message    = 'otp invalid error. (Ref: ' . $log->id . ')';
                }
                
            }else{
                //store sms to log
                $log                = new Otplogs;
                $log->phone         = $body->phone;
                $log->type          = 'error';
                $log->code          = $sms_response['code'];
                $log->detail        = 'Confirm error | ' . $sms_response['detail'];
                $log->save();

                $result->status     = false;
                $result->expired    = $sms_response['code'] == '5000' ? true : false;
                $result->message    = 'confirm otp error. (Ref: ' . $log->id . ')';

                if($sms_response['code'] != '5000'){
                    //send line noti
                    try{
                        $message = 'Confirm OTP error : ' . "\n"
                                . 'phone : ' . $body->phone . "\n"
                                . 'error code : ' . $sms_response['code'] . "\n"
                                . 'detail : ' . $sms_response['detail'] . "\n"
                                . 'log id : ' . $log->id;

                        $line           = new LineNoti;
                        $line->sendMessage($message);
                    }catch(Exception $e){

                    }
                }
            }
        }else{
            $result->status = false;
            $result->message = 'token not found.';
        }

        return $result;
    }

    public function handleStatus($code){
        $result = new stdClass;

        switch($code){
            case null:
                $result->status = false;
                $result->message = 'Status code is null';
                break;
            case '100':
                $result->status = false;
                $result->message = 'Input parameter is missing.';
                break;
            case '101':
                $result->status = false;
                $result->message = 'Phone number not found.';
                break;
            case '102':
                $result->status = false;
                $result->message = 'Schedule not found.';
                break;
            case '103':
                $result->status = false;
                $result->message = 'Waiting to send message.';
                break;
            case '104':
                $result->status = false;
                $result->message = 'Can\'t send OTP, can send 1 phone number.';
                break;
            case '105':
                $result->status = false;
                $result->message = 'Input filename incorrect.';
                break;
            case '106':
                $result->status = false;
                $result->message = 'Input file data format incorrect';
                break;
            case '107':
                $result->status = false;
                $result->message = 'Input phone invalid.';
                break;
            case '200':
                $result->status = false;
                $result->message = 'Username or password incorrect.';
                break;
            case '300':
                $result->status = false;
                $result->message = 'Sender name not found.';
                break;
            case '400':
                $result->status = false;
                $result->message = 'Credit insufficient.';
                break;
            case '500':
                $result->status = false;
                $result->message = 'Account SMSMKT is expired.';
                break;
            case '600':
                $result->status = false;
                $result->message = 'Phone number over 1,000.';
                break;
            case '700':
                $result->status = false;
                $result->message = 'Transaction not found.';
                break;
            case '800':
                $result->status = false;
                $result->message = 'Send date format incorrect.';
                break;
            case '801':
                $result->status = false;
                $result->message = 'Send  date less than 5 minutes.';
                break;
            case '900':
                $result->status = false;
                $result->message = 'Phone number over 100,000.';
                break;
            case '1000':
                $result->status = false;
                $result->message = 'Message input error, Have special character.';
                break;
            case '1001':
                $result->status = false;
                $result->message = 'Server Error.';
                break;
            case '1002':
                $result->status = false;
                $result->message = 'Token invalid signature.';
                break;
            case '1003':
                $result->status = false;
                $result->message = 'Can\'t send sms, Message hasn\'t tag <TRACKING_URL>.';
                break;
            case '1004':
                $result->status = false;
                $result->message = 'API KEY/SECRET KEY incorrect.';
                break;
            case '1005':
                $result->status = false;
                $result->message = 'Project key not found.';
                break;
            case '1006':
                $result->status = false;
                $result->message = 'Token not found.';
                break;
            case '1007':
                $result->status = false;
                $result->message = 'Permission denied.';
                break;
            case '1008':
                $result->status = false;
                $result->message = 'Data not found.';
                break;
            case '1010':
                $result->status = false;
                $result->message = 'Too many request, please try again after an 10 minutes.';
                break;
            case '5000':
                $result->status = false;
                $result->message = 'Can\'t validate token, Your token is expire.';
                break;
            case '9999':
                $result->status = false;
                $result->message = 'Something went wrong, please try again.';
                break;
            default:
                $result->status = true;
                $result->message = 'OK. Message is sent.';
                break;
        }

        return $result;
    }
}
