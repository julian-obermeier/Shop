<?php
namespace App\Console\Commands;

use App\Models\Order;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorOrderDeadlines extends Command
{
    protected $signature='orders:deadlines';
    protected $description='Monitor shipping deadlines and create warnings or overdue states';

    public function handle(NotificationService $notifications): int
    {
        $orders=Order::with('user')
            ->whereIn('status',['waiting_shipping','shipping_overdue'])
            ->whereNotNull('shipping_due_at')
            ->whereDoesntHave('shipment')
            ->get();

        foreach($orders as $order){
            if($order->shipping_due_at->isPast() && $order->status!=='shipping_overdue'){
                DB::transaction(function() use($order){
                    $order->update(['status'=>'shipping_overdue']);
                    $order->statusHistory()->create([
                        'changed_by'=>null,
                        'from_status'=>'waiting_shipping',
                        'to_status'=>'shipping_overdue',
                        'reason'=>'Versandfrist automatisch überschritten',
                    ]);
                });

                $notifications->send(
                    $order->user,
                    'shipping_overdue',
                    'Versandfrist überschritten',
                    'Die Versandfrist für Auftrag #'.$order->order_number.' ist abgelaufen. Bitte melde den Versand schnellstmöglich.',
                    route('orders.show',$order),
                    ['order_id'=>$order->id]
                );
                continue;
            }

            if(
                $order->status==='waiting_shipping'
                && $order->shipping_due_at->isFuture()
                && $order->shipping_due_at->lte(now()->addHours(6))
            ){
                $exists=UserNotification::where('user_id',$order->user_id)
                    ->where('type','shipping_due_soon')
                    ->where('data->order_id',$order->id)
                    ->exists();

                if(!$exists){
                    $notifications->send(
                        $order->user,
                        'shipping_due_soon',
                        'Versandfrist läuft bald ab',
                        'Für Auftrag #'.$order->order_number.' endet die Versandfrist am '.$order->shipping_due_at->format('d.m.Y H:i').' Uhr.',
                        route('orders.show',$order),
                        ['order_id'=>$order->id]
                    );
                }
            }
        }

        return self::SUCCESS;
    }
}
