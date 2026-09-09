<?php
namespace Database\Seeders;
use App\Models\Label; use App\Models\Permission; use App\Models\Role; use App\Models\Status; use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder { public function run():void {
 $permissions=['tickets.create'=>'Criar tickets','tickets.view_all'=>'Visualizar todos os tickets','tickets.assign'=>'Atribuir responsáveis','tickets.manage'=>'Editar tickets','tickets.complete'=>'Concluir tickets','tickets.delete'=>'Enviar para lixeira','tickets.force_delete'=>'Excluir definitivamente','users.manage'=>'Gerenciar usuários','roles.manage'=>'Gerenciar cargos','statuses.manage'=>'Gerenciar status','labels.manage'=>'Gerenciar etiquetas','audit.view'=>'Visualizar auditoria','settings.manage'=>'Gerenciar configurações','integrations.manage'=>'Gerenciar integrações'];
 foreach($permissions as $key=>$name) Permission::firstOrCreate(['key'=>$key],['name'=>$name,'group'=>strtok($key,'.')]);
 $super=Role::firstOrCreate(['name'=>'Super Admin'],['protected'=>true]);$super->permissions()->sync(Permission::pluck('id'));
 $basic=Role::firstOrCreate(['name'=>'Usuário interno'],['protected'=>true]);$basic->permissions()->sync(Permission::whereIn('key',['tickets.create'])->pluck('id'));
 foreach([['Novo','open','#7C3AED'],['Em análise','open','#2563EB'],['Em andamento','in_progress','#0891B2'],['Aguardando cliente','waiting','#D97706'],['Aguardando terceiro','waiting','#D97706'],['Aguardando aprovação','completion_requested','#9333EA'],['Resolvido','completed','#16A34A'],['Fechado','completed','#15803D'],['Cancelado','cancelled','#64748B']] as $i=>$s) Status::firstOrCreate(['name'=>$s[0]],['category'=>$s[1],'color'=>$s[2],'position'=>$i]);
 Label::firstOrCreate(['name'=>'Atrasada'],['color'=>'#DC2626','system'=>true]);
 }}
