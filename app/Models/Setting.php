<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Setting extends Model {
    protected $guarded=[];
    public static function valueOf(string $key, mixed $default=null): mixed
    {
        $row=static::where('key',$key)->first();
        if(!$row) return $default;
        return match($row->type){
            'bool'=>filter_var($row->value,FILTER_VALIDATE_BOOLEAN),
            'int'=>(int)$row->value,
            'float'=>(float)$row->value,
            'json'=>json_decode($row->value??'null',true) ?? $default,
            default=>$row->value,
        };
    }
}
