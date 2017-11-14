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

class ArticleController extends Controller
{
    public function getList(Request $request){
        

    }

    public function getDetail(Request $request){
        $id = $request->input('id');
         
    }


    public function getPictures(Request $request){

    }

    public function edit(Request $request){
        $id =$request->session()->get('userId');
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
