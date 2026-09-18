<?php
namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageSanitizer
{
    public function store(UploadedFile $file, string $disk, string $folder, int $maxWidth=2400, int $maxHeight=2400): array
    {
        if(!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')){
            throw new RuntimeException('Die PHP-GD-Erweiterung ist für sichere Bildverarbeitung erforderlich.');
        }

        $binary=file_get_contents($file->getRealPath());
        if($binary===false) throw new RuntimeException('Bilddatei konnte nicht gelesen werden.');

        $info=@getimagesizefromstring($binary);
        if(!$info) throw new RuntimeException('Ungültige Bilddatei.');

        [$width,$height]=$info;
        $source=@imagecreatefromstring($binary);
        if(!$source) throw new RuntimeException('Bilddatei konnte nicht verarbeitet werden.');

        $scale=min(1,$maxWidth/max(1,$width),$maxHeight/max(1,$height));
        $targetWidth=max(1,(int)round($width*$scale));
        $targetHeight=max(1,(int)round($height*$scale));

        $target=imagecreatetruecolor($targetWidth,$targetHeight);
        $mime=$info['mime']??'image/jpeg';

        if(in_array($mime,['image/png','image/webp'],true)){
            imagealphablending($target,false);
            imagesavealpha($target,true);
            $transparent=imagecolorallocatealpha($target,0,0,0,127);
            imagefilledrectangle($target,0,0,$targetWidth,$targetHeight,$transparent);
        }

        imagecopyresampled($target,$source,0,0,0,0,$targetWidth,$targetHeight,$width,$height);

        ob_start();
        $extension='jpg';
        $storedMime='image/jpeg';

        if($mime==='image/png'){
            imagepng($target,null,7);
            $extension='png';
            $storedMime='image/png';
        } elseif($mime==='image/webp' && function_exists('imagewebp')){
            imagewebp($target,null,86);
            $extension='webp';
            $storedMime='image/webp';
        } else {
            imagejpeg($target,null,88);
        }

        $clean=ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        if($clean===false || $clean===''){
            throw new RuntimeException('Bilddatei konnte nicht gespeichert werden.');
        }

        $path=trim($folder,'/').'/'.Str::uuid().'.'.$extension;
        $written=Storage::disk($disk)->put($path,$clean);
        if(!$written) throw new RuntimeException('Bilddatei konnte nicht in den Speicher geschrieben werden.');

        return ['path'=>$path,'mime'=>$storedMime,'size'=>strlen($clean)];
    }
}
