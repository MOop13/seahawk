<?php
/**
 * Created by Vim.
 * User: PeterR.O.
 * Date: 17-11-09
 * Time: 16:04
 */

namespace App\Http\Controllers\Api;

use App\Contants\Contants;
use App\Contants\ContantsHttpRes;
use App\Contants\ContantsSendMessage;
use App\Contants\ContantsGameApi;
use App\Contants\ContantsUser;
use App\Events\UserLevelEvent;
use App\Model\UserExt;
use App\Model\Notice;
use App\Model\UserBindPlayer;
use App\Model\ProfitRecord;
use App\Model\LevelConfig;
use App\Utils\HttpClient;
use App\Events\LoginEvent;
use App\Http\Controllers\Controller;
use App\Model\User;
use App\Model\UserBindAgent;
use App\Utils\Env;
use App\Utils\RefereeCode;
use App\Utils\Notices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Log;
use Session;

class UserController extends Controller
{
    public function profile(Request $request){
        event(new LoginEvent());
        $name = $request->session()->get('name');
        $cardType = $request->session()->get('cardType');
        $user = User::find(2);
        dd($user);
        return response()->json(['name' => $name, 'cardType' => $cardType]);
    }

    public function reg(Request $request){
        $name = $request->input('name');
        $password = $request->input('password');
        $smsCode = $request->input('smsCode');
        $phone = $request->input('phone');
        if (empty($name)) {
            return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '姓名为空');
        }
        if (empty($password)) {
            return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '密码为空');
        }
        if (empty($smsCode)) {
            return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '短信验证码为空');
        }
        $refCode = $request->input('refCode');
        $cardType = Env::getEnv('cardType');
        if (empty($cardType)) {
            Log::info('当前游戏未开通房卡后台');
           return $this->fail(29, '当前游戏未开通后台1');
        }
        try {
            if (User::query()->where('card_type', '=', $cardType)->where('phone', '=', $phone)->count() > 0){
               return $this->failArr(ContantsHttpRes::PAIR_USER_REG_用户已经存在);
            };
            $bindUser = null;
            if (!empty($refCode)) {
                $bindUser =User::query()->where('referee_code', '=', $refCode)->first();
                if (empty($bindUser)) {
                    return $this->failArr(ContantsHttpRes::PAIR_REFCODE_推荐码不存在);
                }
            }
            $checkCode = HttpClient::sendMsg($phone,ContantsSendMessage::MESSAGE_ACTION_校验验证码, $smsCode);
            if ($checkCode !== true) {
                Log::info('验证码校验失败' . $checkCode);
                return $this->fail(ContantsHttpRes::USER_REG_验证码校验失败, $checkCode);
            }
//            print_r($bindUser);
            $selfRefCode = RefereeCode::generate($cardType);
            if ($selfRefCode == false) {
                throw new \Exception("generate refCode failed");
            }
            $user = array('card_type' => $cardType, 'nickname' => $name, 'phone' => $phone, 'password' => md5($password),'referee_code'=>$selfRefCode);
            DB::transaction(function () use ($user, $bindUser) {
                $exRes = User::create($user);
//                print_r($exRes);
                $userId = $exRes->getAttribute('id');
                UserExt::create(['user_id' => $userId, 'profit'=>0,'total_profit' => 0, 'total_withdraw' => 0, 'level' => ContantsUser::USER_LEVEL_铜牌]);
                if (!empty($bindUser)) {
                    $higher = $bindUser->getModel()->getAttribute('id');
                    $bindType = 1;
                    $bindRep = UserBindAgent::query()->where('card_type','=', $user['card_type'])->where('lower_level','=',$higher)->first();
                    if (!empty($bindRep)) {
                        $bindType = (int)($bindRep->getModel()->getAttribute('bind_type')) + 1;
                    }
                    UserBindAgent::create(['higher_level' => $higher, 'lower_level' => $userId, 'bind_type' => $bindType, 'card_type' => $user['card_type'] ]);
                }
            }, 5);
            if (!empty($bindUser)) {
                event(new UserLevelEvent($bindUser, $cardType, Contants::USERLEVEL_COND_代理绑定));
            }
        } catch (\Exception $e) {
            return $this->failArr(ContantsHttpRes::PAIR_USER_REG_创建用户失败);
        }
        return $this->success('恭喜您注册成功,返回登录');
    }

    public function login(Request $request){
        $type = $request->input('type');
        $mobile = $request->input('mobile');
        $cardType = Env::getEnv('cardType');

        if (empty($type)) {
            return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '登录方式为空');
        }
        if (empty($mobile)) {
            return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '手机号为空');
        }
        $user = new User;
        $userinfo = $user->getUserInfoByPhone($mobile, $cardType);
        if ($userinfo == null ){
            return $this->fail(ContantsHttpRes::USER_INFO_用户不存在, '账号或密码错误');
        }

        if ($type == 1){
            $password = $request->input('password');
            if (empty($password)) {
                return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '账号或密码错误');
            }
            if(md5($password) == $userinfo->password){
                $LoginTrue = 1 ;
            }else {
                $LoginTrue = 0 ;
            }
        }

        if ($type == 2){
            $smsCode = $request->input('smscode');
            if (empty($smsCode)) {
                return $this->fail(ContantsHttpRes::PUBLIC_请求参数为空, '验证码不正确');
            }
            $checkCode = HttpClient::sendMsg($mobile,ContantsSendMessage::MESSAGE_ACTION_校验验证码, $smsCode);

            if ($checkCode === true ) {
                $LoginTrue = 1 ;
            }else {
                $LoginTrue = 0 ;
            }
        }
        if ($LoginTrue) {
            $request->session()->regenerate();
            $request->session()->put('userId', $userinfo->id);
            $request->session()->put('name', $userinfo->nickname);
            $request->session()->put('phone', $mobile);
            $request->session()->put('cardType', $userinfo->card_type);
            return $this->success();
        } else {
            if ($type == 1 ) {
                 return $this->fail(ContantsHttpRes::USER_LOGIN_登录失败, '账号或密码错误');
            }
            if ($type == 2 ) {
                 return $this->fail(ContantsHttpRes::USER_LOGIN_登录失败, '验证码不正确');
            }
        }
    }

    public function logout(Request $request){
        $request->session()->flush();
        return $this->success();
    }

    public function passwordReset(Request $request){
        $cardType = Env::getEnv('cardType');
        $phone = $request->input('mobile');
        $smsCode = $request->input('smscode');
        $newPassword = $request->input('newpassword');
        $confirmPassword = $request->input('confirmpassword');
        if ($newPassword != $confirmPassword){
            return $this->fail(ContantsHttpRes::PASSWORD_CONFIRM_两次输入密码不同, '两次输入密码不同');
        }
        $user = new User;
        $userInfo = $user->getUserInfoByPhone($phone, $cardType);
        if ($userInfo == null){
            return $this->fail(ContantsHttpRes::USER_INFO_用户不存在, '用户不存在');
        }
        $checkCode = HttpClient::sendMsg($phone,ContantsSendMessage::MESSAGE_ACTION_校验验证码, $smsCode);
        if ($checkCode !==  true ) {
            return $this->fail(ContanstHttpRes::USER_REG_验证码校验失败, '验证码不正确');
        }
        $userInfoUpdate = $user->resetPassword($phone, md5($newPassword), $cardType);
        if ($userInfoUpdate == 1) {
            return $this->success();
        }else{
            return $this->fail(ContantsHttpRes::UPDATE_ERROR_更新失败, '更新失败');
        }
    }

    public function getUserInfo(Request $request) {
        $id = $request->session()->get('userId');
        $name = $request->session()->get('name');
        $cardType = $request->session()->get('cardType');
        $phone = $request->session()->get('phone');
        $date_start = date("Y-m-d");
        $date_end = date("Y-m-d", strtotime(" +1 day"));

        $user = new User;
        $userInfo = $user->getUserInfoByPhone($phone, $cardType);

        $referee_code = $userInfo->referee_code;

        $userExt = new UserExt;
        $userExtInfo = $userExt->getProfitInfoByUserId($id);

        $userBindPlayer = new UserBindPlayer;
        $userBindPlayerCount = $userBindPlayer->getPlayerBindCountByUserId($id, $cardType);
        $userBindPlayerInc = $userBindPlayer->getPlayerNewIncOneDay($id, $cardType, $date_start, $date_end);

        $userBindAgent = new UserBindAgent;
        $userBindAgentCount = $userBindAgent->getBindAgentCountByUserId($id, $cardType);
        $userBindAgentInc = $userBindAgent->getAgentNewIncOneDay($id, $cardType, $date_start, $date_end);

        $userProfit = new  ProfitRecord ;
        $userProfitInfo = $userProfit->getProfitRealTimeByUserId($id, $date_start, $date_end);

        $userLevel = new LevelConfig;
        //todo
        $userLevelInfo = $userLevel->getConfig($cardType, $userExtInfo->level);
        $userNextLevelInfo = $userLevel->getConfig($cardType, ($userExtInfo->level +1) );
        if ($userNextLevelInfo == null ){
            $userNextLevelInfo = $userLevel;
        }

        if ($userInfo->id_card != null && $userInfo->zhifubao != null){
            $verifyStatus = 1 ;
        }else {
            $verifyStatus = 2 ;
        }


        $notice = new Notice;
        $noticeInfo = $notice->getLatestNotice();

        $data['userinfo']['id'] = $id;
        $data['userinfo']['name'] = $name;
        $data['userinfo']['cardType'] = $cardType;
        $data['userinfo']['phone'] = $phone;
        $data['userinfo']['refereecode'] = $referee_code;
        $data['userinfo']['verifystatus'] = $verifyStatus;
        $data['userinfoext']['profit'] = $userProfitInfo;
        $data['userinfoext']['totalprofit'] = $userExtInfo->profit;
        $data['userinfoext']['bindplayercount'] = $userBindPlayerCount;
        $data['userinfoext']['playertodayinc'] = $userBindPlayerInc;
        $data['userinfoext']['bindagentcount'] = $userBindAgentCount;
        $data['userinfoext']['agenttodayinc'] = $userBindAgentInc;
        $data['userlevel']['curlvl'] = $userLevelInfo->user_level;
        $data['userlevel']['nextlvl'] = $userNextLevelInfo->user_level;
        $data['userlevel']['curlvlname'] = $userLevelInfo->name;
        $data['userlevel']['curlvlLogo'] = $userLevelInfo->logo;
        $data['userlevel']['nextlvlname'] = $userNextLevelInfo->name;
        $data['userlevel']['curprofitshare'] = $userLevelInfo->commission_player;
        $data['userlevel']['nextprofitshare'] = $userNextLevelInfo->commission_player;
        $data['userlevel']['nextlvlagent'] = $userNextLevelInfo->cond_user_num;
        $data['userlevel']['nextlvlplayer'] = $userNextLevelInfo->cond_player_num;
        $data['userlvlarr'] = array();
        if ($data['userlevel']['nextlvlagent'] != 0){
            $agent['title'] = '名下代理';
            $agent['leftNum'] = $userBindAgentCount;
            $agent['rightNum'] = $userNextLevelInfo->cond_user_num;
            array_push($data['userlvlarr'], $agent);
        }
        if ($data['userlevel']['nextlvlplayer'] != 0){
            $user['title'] = '名下会员';
            $user['leftNum'] = $userBindPlayerCount;
            $user['rightNum'] = $userNextLevelInfo->cond_player_num;
            array_push($data['userlvlarr'], $user);
        }

        $data['notice'] = (empty($noticeInfo)) ? '': $noticeInfo->comment;

        return $this->success($data);
    }


    public function getUserProfile(Request $request){
        $id = $request->session()->get('userId');
        $name = $request->session()->get('name');
        $cardType = $request->session()->get('cardType');
        $phone = $request->session()->get('phone');

        $userInfo = User::getOne($id);

        $data['name'] = $name;
        $data['gender'] = $userInfo->gender;
        if ($userInfo->id_card != null && $userInfo->zhifubao != null ){
            $data['IDstatus'] = 1 ;
        }else{
            $data['IDstatus'] = 2 ;
        }

        return $this->success($data);
    }


    public function getUserCenterInfo(Request $request){
        $id = $request->session()->get('userId');
        $name = $request->session()->get('name');
        $cardType = $request->session()->get('cardType');
        $phone = $request->session()->get('phone');

        $user = new User;
        $userInfo = $user->getUserInfoByPhone($phone, $cardType);

        $referee_code = $userInfo->referee_code;
        $date_created = $userInfo->created;

        $userExt = new UserExt;
        $userExtInfo = $userExt->getProfitInfoByUserId($id);

        $userBindPlayer = new UserBindPlayer;
        $userBindPlayerCount = $userBindPlayer->getPlayerBindCountByUserId($id, $cardType);

        $userBindAgent = new UserBindAgent;
        $userBindAgentInfo = $userBindAgent->getHigherBindAgentByID($id, $cardType);
        $userBindAgentCount = $userBindAgent->getBindAgentCountByUserId($id, $cardType);

        $userLevel = new LevelConfig;
        $userLevelInfo = $userLevel->getConfig($cardType, $userExtInfo->level);

        $data['id'] = $id ;
        $data['level'] = $userLevelInfo->user_level;
        $data['higheragent'] = empty($userBindAgentInfo)? '' : $userBindAgentInfo->higher_level;
        $data['playernum'] = $userBindPlayerCount;
        $data['agentnum'] = $userBindAgentCount;
        $data['created'] = $date_created;
        $data['referee_code'] = $referee_code;
        $data['curlvlname'] = $userLevelInfo->name;

        return $this->success($data);
    }

    public function edit(Request $request){
        $id =$request->session()->get('userId');
        //$name = $request->input('name');
        $gender = $request->input('gender');
        $cardType = $request->session()->get('cardType');

        $user = new User;
        if ($gender == 1 ){
            $change = 2;
        }elseif($gender == 2){
            $change = 1;
        }
        $userInfoUpdate = $user->updateUserGenderById($id, $cardType, $change);
        if ( $userInfoUpdate == 1) {
            return $this->success('更新成功');
        }else{
            return $this->fail( ContantsHttpRes::UPDATE_ERROR_更新失败, '更新失败');
        }
    }

    public function getBindLowerDetail(Request $request){
        $id = $request->session()->get('userId');
        $cardType = $request->session()->get('cardType');
        $type = $request->input('type');

        switch ($type){
            case '1' :
                $userBindPlayer = new UserBindPlayer;
                $userBindPlayerCount = $userBindPlayer->getPlayerBindCountByUserId($id, $cardType);
                $userBindPlayerDetailArr = $userBindPlayer->getPlayerBindInfoByUserId($id, $cardType);

                if ($userBindPlayerDetailArr != null){
                    foreach( $userBindPlayerDetailArr as $key => $value ){
                        $playerInfo = HttpClient::gameApi(ContantsGameApi::API_TYPE_玩家信息, $cardType, $value->player_id, $num = null);
                        if ($playerInfo !== false) {
                            $value->player_name = $playerInfo->name;
                            $value->card_num = $playerInfo->num;
                        }
                    }
                }
                return $this->success($userBindPlayerDetailArr);
            case '2' :
                $userBindAgent = new UserBindAgent;
            //    $userBindAgentCount = $userBindAgent->getBindAgentCountByUserId($id, $cardType);
                $userBindAgentDetailArr = $userBindAgent->getBindAgentDetailByUserId($id, $cardType);

                if($userBindAgentDetailArr != null){
                    foreach( $userBindAgentDetailArr as $key => $value ){
                        $count = $userBindAgent->getBindAgentCountByUserId($value->lower_level, $cardType);
                        if ($count !== false){
                            $value->lower_bind_count = $count;
                        }
                    }
                }
                return $this->success($userBindAgentDetailArr);
        }
    }

    public function getUserExt(Request $request){
        $userId = $request->session()->get('userId');
        $cardType = Env::getEnv('cardType');

        $ext = UserExt::getUserExt($userId);

        return $this->success($ext);
    }

    public function getBindInfo(Request $request){
        $userId = $request->session()->get('userId');
        $cardType = Env::getEnv('cardType');
        $bindStatus = $request->input('status');

        $user = User::getOne($userId);

        $user->id_card =  substr_replace($user->id_card,"*******",10,7);
        $user->phone = substr_replace($user->phone,'******',3,6);
        $length = strlen($user->zhifubao);
        $len_name = strlen($user->nickname);

        $user->zhifubao = substr_replace($user->zhifubao,'*****',floor($length/2), $length );

        return $this->success($user);
    }

    public function verify(Request $request){
        $userId = $request->session()->get('userId');
        $cardType = Env::getEnv('cardType');
        $name = $request->input('name');
        $ID_No = $request->input('id_card');
        $alipay = $request->input('alipay');

        $user = User::getOne($userId);
        if ($user->zhifubao != null) {
            return $this->fail(ContantsHttpRes::WITHDRAW_支付宝账户已绑定, '支付宝账号已绑定，请联系客服修改');
        }
        $result = User::updateOne($userId, array('nickname' => $name, 'id_card' => $ID_No, 'zhifubao' => $alipay));

        return $this->success($result);
    }


}
