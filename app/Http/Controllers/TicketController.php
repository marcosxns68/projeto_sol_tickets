<?php
namespace App\Http\Controllers;
use App\Models\Department;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Http\Request;
class TicketController extends Controller {
 public function index(){return redirect()->route('dashboard');}
 public function create(){return view('tickets.create',['statuses'=>Status::where('active',true)->orderBy('position')->get(),'departments'=>Department::where('active',true)->get()]);}
 public function store(Request $r){$data=$r->validate(['title'=>['required','string','max:180'],'description'=>['required','string'],'priority'=>['required','in:low,normal,high,urgent'],'due_at'=>['required','date','after:now'],'department_id'=>['nullable','exists:departments,id']]); $data+=['number'=>Ticket::nextNumber(),'origin'=>'internal','creator_id'=>$r->user()->id,'status_id'=>Status::where('category','open')->orderBy('position')->value('id')]; $ticket=Ticket::create($data); return redirect()->route('tickets.show',$ticket)->with('success','Ticket criado com sucesso.');}
 public function show(Request $r,Ticket $ticket){abort_unless(Ticket::visibleTo($r->user())->whereKey($ticket)->exists(),403); return view('tickets.show',['ticket'=>$ticket->load(['status','assignee','participants','labels','checklist','comments.user'])]);}
 public function edit(Ticket $ticket){abort(501);} public function update(Request $r,Ticket $ticket){abort(501);}
}
