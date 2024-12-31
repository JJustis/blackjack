// security.php
<?php
class SecurityManager {
    private $conn;
    private const KEY_LENGTH = 32;
    private const HASH_ALGO = 'sha256';

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function generateSecurityKey() {
        return bin2hex(random_bytes(self::KEY_LENGTH));
    }

    public function hashKey($key) {
        return hash(self::HASH_ALGO, $key);
    }

    public function createUserSession($username) {
        $securityKey = $this->generateSecurityKey();
        $keyHash = $this->hashKey($securityKey);
        
        // Store hash in database
        $stmt = $this->conn->prepare("UPDATE members SET security_hash = ?, last_session = NOW() WHERE username = ?");
        $stmt->bind_param("ss", $keyHash, $username);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            // Set session and cookie
            $_SESSION['username'] = $username;
            setcookie('session_id', $keyHash, time() + (86400 * 30), "/", "", true, true); // 30 days, secure, httponly
            
            return [
                'success' => true,
                'security_key' => $securityKey,
                'message' => 'IMPORTANT: Save this security key somewhere safe. You will need it to access your account: ' . $securityKey
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to create session'];
    }

    public function validateSession($username, $securityKey) {
        $providedHash = $this->hashKey($securityKey);
        
        $stmt = $this->conn->prepare("SELECT security_hash FROM members WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (hash_equals($row['security_hash'], $providedHash)) {
                $_SESSION['username'] = $username;
                return ['success' => true, 'message' => 'Session validated'];
            }
        }
        
        return ['success' => false, 'message' => 'Invalid security key'];
    }

    public function checkSession() {
        if (isset($_SESSION['username']) && isset($_COOKIE['session_id'])) {
            $stmt = $this->conn->prepare("SELECT security_hash FROM members WHERE username = ?");
            $stmt->bind_param("s", $_SESSION['username']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if (hash_equals($row['security_hash'], $_COOKIE['session_id'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
