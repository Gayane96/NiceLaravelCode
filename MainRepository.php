<?php

namespace App\Repositories;

use App\Models\Members;
use App\Models\CC_immo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\CC_history as History;
use App\Models\CC_blacklist as Blacklist;
use App\Models\PLZ_group as PlzGroup;
use App\Models\cc_offices as Office;
use App\Models\cc_regions as Region;
use App\Models\Zipcodes;
use App\Exports\immoExport;
use App\Models\CC_members_plz as MembersPlz;
use App\Models\CC_Cross_members_PG as CrossMembers;
use GenderApi\Client as GenderApiClient;
use Illuminate\Support\Facades\Http;

/**
 * Class MainRepository.
 */
class MainRepository
{
    /**
     * @var CC_immo
     * @var Members
     */
    protected $immoModel;
    protected $membersModel;


    /**
     * MainRepository constructor.
     * @param CC_immo $immoModel 
     */
    public function __construct(CC_immo $immoModel, Members $membersModel)
    {
        $this->immoModel = $immoModel;
        $this->membersModel = $membersModel;
    }

    public function getAllData($id = null)
    {
        $result = $this->immoModel->select('*');
        if ($id) {
            $result->where('id', $id);
        } else {
            $result->where(function ($query1) {
                $query1->where('Status', 10)
                    ->where(function ($query2) {
                        $query2->where('Next', '<', date('Y-m-d H:i:s'))->orWhere('Next', 'Null');
                    })->where(function ($query3) {
                        $query3->where('lock_until', '<', date('Y-m-d H:i:s'))->orWhere('lock_until', 'Null');
                    });
            });
            $plz_list = [];
            $plz_list[] = array_map(function ($item) {
                return $item->PLZ;
            }, $this->getUserPLZ(Auth::id()));
            if (!empty($plz_list[0])) {
                $result->whereIn('object_zip', $plz_list[0]);
            }
        }
        $result->orderBy('Status', 'ASC')->orderBy('Next', 'ASC')->orderBy('ID', 'DESC');
        if (empty($result->first())) {
            $result = $this->immoModel->select('*');
            if ($id > 0) {
                $result->where('id', $id);
            } else {
                $result->where(function ($query1) {
                    $query1
                        ->where('Status', 8)
                        ->orWhere('Status', 9)
                        ->orWhere('Status', 11)
                        ->orWhere([['Status', 12], ['Next', '<', date('Y-m-d H:i:s')]])
                        ->orWhere([['Status', 10], ['Next', '<', date('Y-m-d H:i:s')]])
                        ->orWhere([['Status', 8], ['Next', '<', date('Y-m-d H:i:s')]])
                        ->orWhere([['Status', 9], ['Next', '<', date('Y-m-d H:i:s')]]);
                })
                    ->where(function ($query2) {
                        $query2
                            ->orWhere('lock_until', 'Null');
                    });
                if (!empty($plz_list[0])) {
                    $result->whereIn('object_zip', $plz_list[0]);
                }
            }
            $result->orderBy('Status', 'ASC')->orderBy('Next', 'ASC')->orderBy('ID', 'DESC');
        }
                if(!empty($result->first())){
        
                    $this->lockRecord($result->first()->ID);
                }
        return $result->first();
    }
    public function getUserPLZ($usr_id)
    {
        $sql = "SELECT DISTINCT z.PLZ FROM (zipcodes z INNER JOIN cc_cross_pg_zipcode cpz ON cpz.zip_id = z.zip_id) INNER JOIN cc_cross_members_pg cmp ON cmp.cc_pg_name = cpz.cc_pg_name WHERE cmp.cc_member_id =  " . $usr_id . "  ";
        $plz = DB::select($sql);
        return $plz;
    }
    public function lockRecord($id)
    {
        $time = time() + (30 * 60);
        $this->immoModel->where('ID', $id)->update(['lock_until' => date('Y-m-d H:i:s', $time)]);
    }
    public function update($data)
    {
        if($data['status'] == 666){
            if(empty($data->Telephone)){
                    Blacklist::insert([
                        'name' => $data['Lastname'],
                        'number' => $data['Telephone'],
                        'timestamp' => $data['meeting_timestamp'],
                        'immo_id' => $data['ID'],
                    ]);
                    return false;
            }
            else{
                Blacklist::insert([
                    'name' => $data['Lastname'],
                    'number' => $data['Telephone2'],
                    'timestamp' => $data['meeting_timestamp'],
                    'immo_id' => $data['ID'],
                ]);
                return false;                
            }
        }

        return $this->immoModel->where('ID', $data['ID'])->update($data);
    }
    function saveHistory($immo_id, $remarks, $status, $data)
    {
        if ($status == 20) {
            $remarks .= ' Treffpunkt: ' . $data['meeting_street'] . ', ' . $data['meeting_zip'] . ' ' . $data['meeting_city'] . ' - ' . $data['meeting_timestamp'];
        } elseif ($status == 10) {
            $remarks .= ' Wiedervorlage: ' . $data['next'];
        }

        if (Auth::check())
            $usr_id = Auth::id();
        else
            $usr_id = 26;
        return History::insert(
            [
                'immo_id' => $immo_id,
                'member_id' => $usr_id,
                'remark' => $remarks,
                'status' => $status
            ]
        );
    }
    public function getAllCallList($data = [])
    {

        $result = $this->immoModel->select('*');
        if (!empty($data)) {
            $result
                ->where('Lastname', 'like', '%' . $data['search'] . '%')
                ->orWhere('Telephone', 'like', $data['search'] . '%')
                ->orWhere('Telephone2', 'like', $data['search'] . '%')
                ->orWhere('Telephone2', 'like', $data['search']);
        }
        return $result->orderBy('last_update', 'DESC')->paginate(20);
    }
    public function findImmoByPhone($number)
    {
        $result = $this->immoModel->where('Telephone', $number)->orWhere('Telephone2', $number)->first();
        if (empty($result)) {
            $result = Blacklist::where('number', $number)->first();
        }
        return $result;
    }
    public function saveData($data)
    {
        return $this->immoModel->create($data);
    }
    public function findDataByDate($data)
    {

        $result = $this->immoModel->select('*');
        $result->where([['Timestamp', '<=', $data['to']], ['Timestamp', '>=', $data['from']]]);
        $plz_list = [];
        $plz_list[] = array_map(function ($item) {
            return $item->PLZ;
        }, $this->getUserPLZ(Auth::id()));
        if (!empty($plz_list[0])) {
            $result->whereIn('object_zip', $plz_list[0]);
        }
        $result->orderBy('ID', 'ASC')->limit(1000);
        return new immoExport($result->get());
    }
    public function getCcUsers()
    {
        return $this->membersModel->where('isCC', 1)->orderByRaw('firstname , lastname, id asc')->get();
    }
    public function getPlzGroups()
    {
        return PlzGroup::orderBy('cc_pg_name', 'asc')->get();
    }
    public function savePgMember($data)
    {
        $result = CrossMembers::create($data);
        return $result ? "success" : false;
    }
    public function getPgMembers()
    {
        $res = PlzGroup::orderBy('cc_pg_name', 'asc')->get();
        return $res;
    }
    public function getAddressCount()
    {
        $res = $this->immoModel->select(
            DB::raw(
                'cc_cross_pg_zipcode.cc_pg_name,zipcodes.PLZ,zipcodes.Ort,cc_immo.Telephone,
                        sum(case when cc_immo.status = 11 then 1 else 0 end) AS status11Count,
                        sum(case when cc_immo.status = 10 then 1 else 0 end) AS status10Count,
                        sum(case when cc_immo.status = 12 then 1 else 0 end) AS status12Count'
            )
        )
            ->join('zipcodes', 'zipcodes.PLZ', '=', 'cc_immo.object_zip')
            ->join('cc_cross_pg_zipcode', 'cc_cross_pg_zipcode.zip_id', '=', 'zipcodes.zip_id')
            ->groupBy('cc_cross_pg_zipcode.cc_pg_name')->paginate(20);
        return $res;
    }
    public function deletePgMember($data)
    {
        return CrossMembers::where([['cc_pg_name', $data['cc_pg_name']], ['cc_member_id', $data['member_id']]])->delete();
    }
    public function refreshAddresses()
    {
        return $this->immoModel
            ->whereIn('status', [10, 12])
            ->where([['next', '>', date('Y-m-d H:i:s')]])
            ->update(
                [
                    'next_saved' => DB::raw("`next`"),
                    'lock_until' => date('Y-m-d H:i:s'),
                    'next' => date('Y-m-d H:i:s')
                ]
            );
    }
    public function recoverAddresses()
    {
        return $this->immoModel->whereIn('status', [10, 12])->where([['next_saved', '>', DB::raw("`next`")], ['next_saved', '>', date('Y-m-d H:i:s')]])->update(['next' => DB::raw("`next_saved`")]);
    }
    public function getUserDataById($id)
    {
        return $this->immoModel->where('id', $id)->first();
    }
    function getAllAgents()
    {
        return $this->membersModel->where('isAgent', 1)->orWhere('isAdmin', 1)->orWhere('isSuperAdmin', 1)->orderByRaw("lastname, firstname ASC")->get();
    }
    public function getAgentsById($id)
    {
        if (Auth::user()->isSuperAdmin) {
            return $this->getAllAgents();
        } else {
            return $this->membersModel
                ->where('id', $id)
                ->orWhere(function ($query) use ($id) {
                    $query->where(function ($query1) use ($id) {
                        $query1->where('isAgent', 1)->orWhere('isAdmin', 1)->orWhere('isSuperAdmin', 1);
                    })
                        ->where('leader', $id);
                })
                ->orderByRaw("lastname, firstname ASC")->get();
        }
    }
    function getFollowers($onlyAgents = false, $onlyCC = false,$fields='*') {
        $followers = $this->membersModel->select($fields);
        if ($onlyAgents){
            $followers->where(function($query){
                $query->where('isAgent', 1)->orWhere('isAdmin', 1)->orWhere('isSuperAdmin', 1);
            });
        }
        if ($onlyCC){
            $followers->where(function($query){
                $query->where('isAgent', 0)->where('isAdmin', 0)->orWhere('isSuperAdmin', 0);
            });
        }
        if (!Auth::user()->isSuperAdmin){
            $followers->where(function($query){
                $query->where('leader', Auth::id())->orWhere('id', Auth::id());
            });
        }
        $followers->orderByRaw('lastname, firstname ASC');
        return $followers->get();
        
    }
    public function getShootings($status = false,$agent = false){
        $followers = $this->getFollowers(false,false,'id');
        $followersIds= [];
        $followersIds[] = array_map(function ($item) {
            return $item['id'];
        }, $followers->toArray());
        $followersIds = $followersIds[0];
        $shootings = $this->immoModel
        ->whereBetween('status',[20,29])
        ->where(function($query) use($followersIds){
            $query->whereIn('last_user',$followersIds)->orWhereIn('meeting_user',$followersIds);
        });
        if($status){
            $shootings->where('status',$status);
        }
        if($agent){
            $shootings->where('meeting_user',$agent);
        }
        $shootings->orderBy('Timestamp','DESC')->orderBy('meeting_timestamp','DESC');
        return $shootings->paginate(5);
    }
    public function getReport($days){
        $followers = $this->getFollowers(false, false);
        $followersIds= [];
        $followersIds[] = array_map(function ($item) {
            return $item['id'];
        }, $followers->toArray());
        $followersIds = $followersIds[0];
        $time = time();
        $time_before = $time - ($days-1)*24*3600;
        $timestamp_now = date('Y-m-d H:i:s', $time);
        $timestamp_before = date('Y-m-d', $time_before).' 00:00:01';

        $result = History::select(DB::raw(
            'cc_history.*,members.firstname,members.lastname,members.username, DATE(cc_history.timestamp) as date,
            sum(case when cc_history.status >=20 and cc_history.status<=29 then 1 else 0 end) AS shootings,
            sum(case when cc_history.status = 10 then 1 else 0 end) AS wiedervorlagen,
            sum(case when cc_history.status =90 or cc_history.status=91 then 1 else 0 end) AS kickouts,COUNT(cc_history.id) AS calls'
        ))->whereIn('member_id',$followersIds)
        ->whereBetween('timestamp',[$timestamp_before,$timestamp_now])
        ->groupByRaw('cc_history.member_id,DATE(cc_history.timestamp)')
        ->orderByRaw('member_id,id asc')
        ->join('members', 'members.id', '=', 'cc_history.member_id')
        ->paginate(20);

        return $result;
  }
    public function getDataByIdStatus($data){
        $result = History::where('member_id',$data['member_id'])->where(DB::raw("(DATE(timestamp))"), $data['date']);
        if(isset($data['status'])){
            $status_ids = explode(',',$data['status']);
            $result->whereIn('status',$status_ids);
        }
        return $result->get();
    }
    public function getUserZipList($id){
        return MembersPlz::where('member_id',63)->orderBy('PLZ','ASC')->paginate(20);
    }
    function saveMemberPlzActive($member_id, $plz, $active, $sho_member) {
        return MembersPlz::where('member_id',63)->where('PLZ',$plz)->update(['sho_member'=>$sho_member,'active'=>$active]);
    }
    public function getUserByNumber($data){
        return CC_immo::where("Telephone", '=', $data)->first('id');
    } 
    public function getUsers(){
        $users = Members::select('firstname', 'lastname', 'email', 'isAdmin', 'isAgent', 'isSuperAdmin', 'isCC', 'isExternalCC')->paginate(20);
        return $users;
    }
    public function showOffices(){
        return Office::paginate(20);
    }
    public function addNewOffice($data){
        // dd($data->input('region'));
        foreach($data->input('region') as $reg){
            $response = Http::get('https://geocoder.api.here.com/6.2/geocode.json?app_id=adDmASOlPQMQSj5Oev7o&app_code=tUUbBpnWz1mI7FOfK59Oqg&gen=9&searchtext='.$data->input('town')."+".$data->input('street')."+".$data->input('number')."+".$reg);
            Zipcodes::insert([
                "PLZ" => $response['Response']['View'][0]['Result'][0]['Location']['Address']['PostalCode'],
                "Ort" => $response['Response']['View'][0]['Result'][0]['Location']['Address']['City'],
                "Bundesland" =>$response['Response']['View'][0]['Result'][0]['Location']['Address']['State'],
            ]);
         }
        dd('insert test');
        Office::insert([
            'name' => $data->input('Office_name'),
            'starts' => $data->input('starts'),
            'ends' => $data->input('ends'),
            'calendly_link' => $data->input('calendly_link'),
            'Town' => $data->input('town'),
            'Street' => $data->input('street'),
            'Number' => $data->input('number'),
            'Region_id' => $reg,
            'manager_firstname' => $data->input('manager_firstname'),
            'manager_lastname' => $data->input('manager_lastname'),
            'manager_email' => $data->input('meneger_email'),
        ]);
        return redirect('/Office');
    }
    public function addNewUser($data){
        // foreach($data->user as $u){
            // Members::insert([
            //     'firstname'
            // ]);
        // }
        if($data['user_type'] == 'admin'){
            Members::insert([
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'isAdmin' => 1,
            ]);
        }
        elseif($data['user_type'] == 'teem_lead'){
            Members::insert([
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'isCC' => 1,
            ]);
        }
        else{
            Members::insert([
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
                'isAgent' => 1,
            ]);
        }
        return redirect('/Users');
    }
}
