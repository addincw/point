<?php

namespace App\Http\Controllers\Api\Plugin\PinPoint\Report;

use App\Http\Resources\Plugin\PinPoint\Report\Performance\PerformanceCollection;
use App\Model\Master\User;
use App\Model\Plugin\PinPoint\SalesVisitation;
use App\Model\Plugin\PinPoint\SalesVisitationDetail;
use App\Http\Controllers\Controller;
use App\Model\Plugin\PinPoint\SalesVisitationTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceReportController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = date('Y-m-d 00:00:00', strtotime($request->get('date_from')));
        $dateTo = date('Y-m-d 23:59:59', strtotime($request->get('date_to')));
        $queryTarget = $this->queryTarget($dateTo);
        $queryCall = $this->queryCall($dateFrom, $dateTo);
        $queryEffectiveCall = $this->queryEffectiveCall($dateFrom, $dateTo);
        $queryValue = $this->queryValue($dateFrom, $dateTo);
        $details = $this->queryDetails($dateFrom, $dateTo);

        $result = User::query()->leftJoinSub($queryTarget, 'queryTarget', function ($join) {
            $join->on('users.id', '=', 'queryTarget.user_id');
        })->leftJoinSub($queryCall, 'queryCall', function ($join) {
            $join->on('users.id', '=', 'queryCall.created_by');
        })->leftJoinSub($queryEffectiveCall, 'queryEffectiveCall', function ($join) {
            $join->on('users.id', '=', 'queryEffectiveCall.created_by');
        })->leftJoinSub($queryValue, 'queryValue', function ($join) {
            $join->on('users.id', '=', 'queryValue.created_by');
        })->select('users.id')
            ->addSelect('users.name')
            ->addSelect('users.first_name')
            ->addSelect('users.last_name')
            ->addSelect('queryTarget.call as target_call')
            ->addSelect('queryTarget.effective_call as target_effective_call')
            ->addSelect('queryTarget.value as target_value')
            ->addSelect('queryCall.total as call')
            ->addSelect('queryEffectiveCall.total as effective_call')
            ->addSelect('queryValue.value as value')
            ->where('queryTarget.call', '>', 0)
            ->groupBy('users.id')
            ->get();

        foreach ($result as $user) {
            $values = array_values($details->filter(function ($value) use ($user) {
                return $value->created_by == $user->id;
            })->all());

            foreach ($values as $value) {
                unset($value->created_by);
            }

            $user->items = $values;
        }

        return new PerformanceCollection($result);
    }

    public function queryTarget($dateTo)
    {
        $query = SalesVisitationTarget::whereIn('date', function ($query) use ($dateTo) {
            $query->selectRaw('max(date)')->from(SalesVisitationTarget::getTableName())->where('date', '<=', $dateTo)->groupBy('user_id');
        });

        $targets = User::leftJoinSub($query, 'query', function ($join) {
            $join->on('users.id', '=', 'query.user_id');
        })->select('query.id as id')
            ->addSelect('users.name as name')
            ->addSelect('users.id as user_id')
            ->addSelect('query.date as date')
            ->addSelect('query.call as call')
            ->addSelect('query.effective_call as effective_call')
            ->addSelect('query.value as value')
            ->groupBy('users.id');

        return $targets;
    }

    public function queryCall($dateFrom, $dateTo)
    {
        return SalesVisitation::join('forms', 'forms.id', '=', 'pin_point_sales_visitations.form_id')
            ->select('forms.created_by as created_by')
            ->addselect(DB::raw('count(forms.id) as total'))
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->groupBy('forms.created_by');
    }

    public function queryEffectiveCall($dateFrom, $dateTo)
    {
        $querySalesVisitationHasDetail = SalesVisitation::join('forms', 'forms.id', '=', 'pin_point_sales_visitations.form_id')
            ->join('pin_point_sales_visitation_details', 'pin_point_sales_visitation_details.sales_visitation_id', '=', 'pin_point_sales_visitations.id')
            ->select('pin_point_sales_visitations.id')
            ->addSelect(DB::raw('sum(pin_point_sales_visitation_details.quantity) as totalQty'))
            ->whereBetween('forms.date', [$dateFrom, $dateTo])
            ->groupBy('pin_point_sales_visitations.id');

        return SalesVisitation::join('forms', 'forms.id', '=', 'pin_point_sales_visitations.form_id')
            ->joinSub($querySalesVisitationHasDetail, 'query_sales_visitation_has_detail', function ($join) {
                $join->on('pin_point_sales_visitations.id', '=', 'query_sales_visitation_has_detail.id');
            })->selectRaw('count(pin_point_sales_visitations.id) as total')
            ->addSelect('forms.created_by')
            ->addSelect(DB::raw('query_sales_visitation_has_detail.totalQty'))
            ->whereBetween('forms.date', [$dateFrom, $dateTo])
            ->groupBy('forms.created_by');
    }

    public function queryValue($dateFrom, $dateTo)
    {
        return SalesVisitation::join('forms', 'forms.id','=',SalesVisitation::getTableName().'.form_id')
            ->join(SalesVisitationDetail::getTableName(), SalesVisitationDetail::getTableName().'.sales_visitation_id', '=', SalesVisitation::getTableName().'.id')
            ->groupBy('forms.created_by')
            ->selectRaw('sum(quantity * price) as value')
            ->whereBetween('forms.date', [$dateFrom, $dateTo])
            ->addSelect('forms.created_by');
    }

    public function queryDetails($dateFrom, $dateTo)
    {
        return SalesVisitation::join('forms', 'forms.id','=',SalesVisitation::getTableName().'.form_id')
            ->leftJoin(SalesVisitationDetail::getTableName(), SalesVisitationDetail::getTableName().'.sales_visitation_id', '=', SalesVisitation::getTableName().'.id')
            ->rightJoin('items', 'items.id', '=', SalesVisitationDetail::getTableName().'.item_id')
            ->groupBy(SalesVisitationDetail::getTableName().'.item_id')
            ->groupBy('forms.created_by')
            ->selectRaw('sum(quantity) as quantity')
            ->addSelect('forms.created_by')
            ->addSelect('items.id as item_id')
            ->whereBetween('forms.date', [$dateFrom, $dateTo])
            ->orderBy('item_id')
            ->get();
    }
}
