<?php
namespace App\Http\Controllers;
use App\Models\Ticket;
use Illuminate\Http\Request;
class DashboardController extends Controller { public function __invoke(Request $r){$tickets=Ticket::visibleTo($r->user())->with(['status','assignee','labels'])->whereNull('trashed_at')->latest()->paginate(20); return view('dashboard',compact('tickets'));} }
