<?php

namespace App\Http\Modules;
use GuzzleHttp\Client;
use stdClass;
use App\Models\UserServices;
use App\Models\UserServiceLog;
use App\Libraries\Line;
use App\Models\UserServiceSmsLogs;

class SMSMKT {

    public function __construct(){
        $this->api_key = config('app.sms_api_key');
        $this->secret_key = config('app.sms_api_secret');
        $this->sender = config('app.sms_sender');

        $this->api_request = new Client([
            'base_uri' => 'https://portal-otp.smsmkt.com',
            'timeout'  => 30.0,
            'headers' => [
                'Content-Type' => 'application/json',
                "api_key" => $this->api_key,
                "secret_key" => $this->secret_key,
                //"api_key" => 'xxxx',
                //"secret_key" => 'yyyyy'
            ],
        ]);
    }

    public function send($body,$user_service_id){
        if ($body['message'] && $body['phone']){
            $response = $this->api_request->post('/api/send-message', [
                'json' => [
                    "message"=> $body['message'],
                    "phone"=> $body['phone'],
                    "sender"=> $this->sender,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $sms_response = json_decode($response->getBody()->getContents(), true);

            $response = new stdClass;

            if($sms_response['code'] == '000'){
                $response->code = $sms_response['code'];
                $response->detail = $sms_response['detail'];
                $response->result = $sms_response['result'];

                //stamp sms log
                $sms_log                    = new UserServiceSmsLogs;
                $sms_log->user_service_id   = $user_service_id;
                $sms_log->sent_to           = $body['phone'];
                $sms_log->message           = $body['message'];
                $sms_log->status            = 'success';
                $sms_log->response_code     = $sms_response['code'];
                $sms_log->response_detail   = $sms_response['detail'];
                $sms_log->response_result   = json_encode($sms_response['result']);

                if(isset($body['is_resend']) && $body['is_resend'] == 1){
                    $sms_log->is_resend     = 1;
                    $sms_log->resend_ref_id = $body['resend_ref_id'];
                }

                $sms_log->save();

                return $response;
            }else{
                if(!isset($body['is_resend'])){
                    //stamp sms log
                    $sms_log                    = new UserServiceSmsLogs;
                    $sms_log->user_service_id   = $user_service_id;
                    $sms_log->sent_to           = $body['phone'];
                    $sms_log->message           = $body['message'];
                    $sms_log->status            = 'error';
                    $sms_log->response_code     = $sms_response['code'];
                    $sms_log->response_detail   = $sms_response['detail'];
                    $sms_log->response_result   = json_encode($sms_response['result']);
                    $sms_log->save();
                }

                $response->code = $sms_response['code'];
                $response->detail = $sms_response['detail'];
                $response->result = json_encode($sms_response['result']);

                return $response;

            }
            
        }

    }

    public function updateCredit($id){
        //get current credit
        $user_service = UserServices::where('id', $id)->first();

        $old_credit                     = $user_service->qty;
        $user_service->qty              = $user_service->qty - 1;
        $user_service->updated_at       = now();
        $user_service->update();

        $user_service_log                   = new UserServiceLog();
        $user_service_log->user_service_id  = $user_service->id;
        $user_service_log->action           = 'used';
        $user_service_log->detail           = 'หักเครดิต sms';
        $user_service_log->remark           = 'หักจาก service sms';
        $user_service_log->qty              = 1;
        $user_service_log->prev_credit      = $old_credit;
        $user_service_log->updated_credit   = $user_service->qty;
        $user_service_log->created_at       = now();
        $user_service_log->updated_at       = now();

        $user_service_log->save();
        

        $response = new stdClass;
        $response->result = 'success';
        $response->detail = 'SMS used 1 credit. remaining '. $user_service->qty . ' credit(s).';

        return $response;
    }

    public function getCompanyCredit(){
        $response = $this->api_request->get('/api/get-credit');
        $sms_response = json_decode($response->getBody()->getContents(), true);

        if($sms_response['code'] != 000){
            try {
                $message = ''
                ."\n".'แจ้งเตือน ERROR SMSMKT (GetCredit): '
                ."\n".'Datetime : ' . date("Y-m-d H:i:s")
                ."\n".'Response code : '. $sms_response['code']
                ."\n".'Response detail : '. $sms_response['detail']
                ."\n".'Response result : '. json_encode($sms_response['result']);
                
                $token = config('app.line_token_dev');
                sendLine($token, $message);

            } catch (Exception $e) {
                
            }

            $response = new stdClass;
            $response->code = $sms_response['code'];
            $response->detail = $sms_response['detail'];
            $response->result = $sms_response['result'];

            return $response;
        }else{
            $response = new stdClass;
            $response->code = $sms_response['code'];
            $response->detail = $sms_response['detail'];
            $response->result = $sms_response['result'];

            return $response;
        }
    }

}
