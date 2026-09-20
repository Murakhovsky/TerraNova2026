<?php
declare(strict_types=1);

namespace App\Application\Identity\Service;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use PDO;
use Throwable;

final readonly class AccountAuthenticationService
{
    private const PUBLIC_ROLES=['buyer','seller','investor','realtor','developer','partner'];

    public function __construct(private PdoConnection $database,private string $organizationId){}

    public function authenticate(array $input): array
    {
        $email=mb_strtolower(trim((string)($input['email']??'')));
        $password=(string)($input['password']??'');
        if($email===''||$password==='') return ['ok'=>false,'message'=>'Вкажіть email і пароль.'];
        $user=$this->findByEmail($email);
        if($user===null||!password_verify($password,(string)($user['password_hash']??''))) return ['ok'=>false,'message'=>'Email або пароль неправильні.'];
        if((string)($user['status']??'')!=='active') return ['ok'=>false,'message'=>'Акаунт неактивний.'];
        $this->touchLogin((int)$user['id']);
        return ['ok'=>true,'message'=>'Вхід виконано.','user'=>$user];
    }

    public function register(array $input): array
    {
        $name=trim((string)($input['full_name']??''));
        $email=mb_strtolower(trim((string)($input['email']??'')));
        $phone=trim((string)($input['phone']??''));
        $password=(string)($input['password']??'');
        $repeat=(string)($input['password_repeat']??'');
        $role=mb_strtolower(trim((string)($input['role']??'buyer')));
        $role=in_array($role,self::PUBLIC_ROLES,true)?$role:'buyer';
        if($name===''||$email===''||$password==='') return ['ok'=>false,'message'=>'Заповніть ім’я, email і пароль.'];
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'message'=>'Вкажіть коректний email.'];
        if(mb_strlen($password)<8) return ['ok'=>false,'message'=>'Пароль має містити щонайменше 8 символів.'];
        if($password!==$repeat) return ['ok'=>false,'message'=>'Паролі не збігаються.'];
        if($this->findByEmail($email)!==null) return ['ok'=>false,'message'=>'Користувач із таким email уже існує.'];

        try{
            $pdo=$this->database->connection();
            $pdo->beginTransaction();
            $s=$pdo->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,phone,role,status) VALUES (:organization_id,:email,:password_hash,:full_name,:phone,:role,\'active\')');
            $s->execute([
                'organization_id'=>$this->organization(),'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),
                'full_name'=>mb_substr($name,0,160),'phone'=>$phone!==''?mb_substr($phone,0,50):null,'role'=>$role,
            ]);
            $id=(int)$pdo->lastInsertId();
            $m=$pdo->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (:organization_id,:user_id,:role,\'ACTIVE\')');
            $m->execute(['organization_id'=>$this->organization(),'user_id'=>$id,'role'=>$role]);
            $pdo->commit();
        }catch(Throwable){
            if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
            return ['ok'=>false,'message'=>'Реєстрацію не вдалося завершити. Спробуйте ще раз.'];
        }
        $user=$this->findById($id);
        if($user===null)return ['ok'=>false,'message'=>'Акаунт створено, але його не вдалося відкрити.'];
        $this->touchLogin($id);
        return ['ok'=>true,'message'=>'Акаунт створено.','user'=>$user];
    }

    private function findByEmail(string $email): ?array
    {
        $s=$this->database->connection()->prepare('SELECT u.id,u.organization_id,u.email,u.password_hash,u.full_name,u.phone,u.role,u.status,m.role AS organization_role FROM tn_users u INNER JOIN cos_organization_memberships m ON m.user_id=u.id AND m.organization_id=:organization_id AND m.status=\'ACTIVE\' WHERE u.email=:email LIMIT 1');
        $s->execute(['organization_id'=>$this->organization(),'email'=>$email]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
    private function findById(int $id): ?array
    {
        $s=$this->database->connection()->prepare('SELECT u.id,u.organization_id,u.email,u.password_hash,u.full_name,u.phone,u.role,u.status,m.role AS organization_role FROM tn_users u INNER JOIN cos_organization_memberships m ON m.user_id=u.id AND m.organization_id=:organization_id AND m.status=\'ACTIVE\' WHERE u.id=:id LIMIT 1');
        $s->execute(['organization_id'=>$this->organization(),'id'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }
    private function touchLogin(int $id): void
    {
        $s=$this->database->connection()->prepare('UPDATE tn_users SET last_login_at=NOW(6) WHERE id=:id LIMIT 1');$s->execute(['id'=>$id]);
    }
    private function organization(): string
    {
        $v=trim($this->organizationId);return $v!==''?$v:'default';
    }
}
