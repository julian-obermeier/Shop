<?php
declare(strict_types=1);

function run_app_migrations(PDO $pdo, string $directory): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      migration VARCHAR(190) NOT NULL UNIQUE,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $files=glob(rtrim($directory,'/').'/*.sql') ?: [];
    sort($files,SORT_STRING);
    $result=['applied'=>[],'skipped'=>[],'warnings'=>[]];

    foreach($files as $file){
        $name=basename($file,'.sql');
        $q=$pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration=?');
        $q->execute([$name]);
        if((int)$q->fetchColumn()>0){$result['skipped'][]=$name;continue;}

        $sql=file_get_contents($file);
        if($sql===false) throw new RuntimeException('Migration konnte nicht gelesen werden: '.$name);

        $statements=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [])));
        foreach($statements as $statement){
            try{
                $pdo->exec($statement);
            }catch(PDOException $e){
                $driverCode=(int)($e->errorInfo[1]??0);
                if(in_array($driverCode,[1050,1060,1061,1068,1091,1826],true)){
                    $result['warnings'][]=$name.': '.($e->errorInfo[2]??$e->getMessage());
                    continue;
                }
                throw new RuntimeException('Migration '.$name.' fehlgeschlagen: '.$e->getMessage(),0,$e);
            }
        }
        $pdo->prepare('INSERT INTO migrations(migration) VALUES(?)')->execute([$name]);
        $result['applied'][]=$name;
    }
    return $result;
}
