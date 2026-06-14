<?php
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('DB_PATH', __DIR__ . '/pkiet.db');

if (!defined('SQLITE3_ASSOC')) {
    define('SQLITE3_INTEGER', 1);
    define('SQLITE3_FLOAT', 2);
    define('SQLITE3_TEXT', 3);
    define('SQLITE3_BLOB', 4);
    define('SQLITE3_NULL', 5);
    define('SQLITE3_ASSOC', 6);
    define('SQLITE3_NUM', 7);
    define('SQLITE3_BOTH', 8);
}

class PdoSQLite3Result {
    private $stmt;
    public function __construct(PDOStatement $stmt) {
        $this->stmt = $stmt;
    }
    public function fetchArray($mode = SQLITE3_ASSOC) {
        if ($mode === SQLITE3_NUM) {
            return $this->stmt->fetch(PDO::FETCH_NUM);
        }
        if ($mode === SQLITE3_BOTH) {
            return $this->stmt->fetch(PDO::FETCH_BOTH);
        }
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }
}

class PdoSQLite3Statement {
    private $stmt;
    public function __construct(PDOStatement $stmt) {
        $this->stmt = $stmt;
    }
    public function bindValue($param, $value, $type = null) {
        if ($type === SQLITE3_NULL || $value === null) {
            $pdoType = PDO::PARAM_NULL;
        } elseif ($type === SQLITE3_INTEGER || is_int($value)) {
            $pdoType = PDO::PARAM_INT;
        } elseif ($type === SQLITE3_FLOAT) {
            $pdoType = PDO::PARAM_STR;
        } elseif ($type === SQLITE3_BLOB) {
            $pdoType = PDO::PARAM_LOB;
        } else {
            $pdoType = PDO::PARAM_STR;
        }
        $this->stmt->bindValue($param, $value, $pdoType);
    }
    public function execute() {
        $this->stmt->execute();
        return new PdoSQLite3Result($this->stmt);
    }
}

class PdoSQLite3 {
    private $pdo;
    public function __construct($path) {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("PRAGMA journal_mode=WAL;");
        $this->pdo->exec("PRAGMA foreign_keys=ON;");
    }
    public function exec($sql) {
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $total = 0;
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            $result = $this->pdo->exec($stmt);
            if ($result === false) {
                $error = $this->pdo->errorInfo();
                throw new RuntimeException('SQLite exec failed: ' . ($error[2] ?? 'unknown error'));
            }
            $total += $result;
        }
        return $total;
    }
    public function query($sql) {
        $stmt = $this->pdo->query($sql);
        return $stmt ? new PdoSQLite3Result($stmt) : false;
    }
    public function prepare($sql) {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            $error = $this->pdo->errorInfo();
            throw new RuntimeException('SQLite prepare failed: ' . ($error[2] ?? 'unknown error'));
        }
        return new PdoSQLite3Statement($stmt);
    }
    public function querySingle($sql) {
        $stmt = $this->pdo->query($sql);
        if (!$stmt) return false;
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? false : $row[0];
    }
    public function lastInsertRowID() {
        return intval($this->pdo->lastInsertId());
    }
}

function getDB() {
    static $db = null;
    if ($db === null) {
        if (class_exists('SQLite3')) {
            $db = new SQLite3(DB_PATH);
            $db->exec("PRAGMA journal_mode=WAL;");
            $db->exec("PRAGMA foreign_keys=ON;");
        } elseif (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $db = new PdoSQLite3(DB_PATH);
        } else {
            jsonResponse(['error' => 'SQLite is not available on this server'], 500);
        }
        initDB($db);
    }
    return $db;
}

function initDB($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            token TEXT PRIMARY KEY,
            created_at INTEGER,
            expires_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            parent_id INTEGER,
            created_at INTEGER,
            updated_at INTEGER,
            UNIQUE(parent_id, name)
        );
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            content TEXT DEFAULT '',
            size INTEGER DEFAULT 0,
            created_at INTEGER,
            updated_at INTEGER,
            folder TEXT DEFAULT '/',
            folder_id INTEGER,
            pinned INTEGER DEFAULT 0,
            FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
        );
    ");
    ensureColumn($db, 'files', 'folder_id', 'INTEGER');
    migrateLegacyFolders($db);
}

function ensureColumn($db, $table, $column, $type) {
    $res = $db->query("PRAGMA table_info($table)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) return;
    }
    $db->exec("ALTER TABLE $table ADD COLUMN $column $type");
}

function migrateLegacyFolders($db) {
    $needsMigration = intval($db->querySingle(
        "SELECT COUNT(*) FROM files WHERE folder IS NOT NULL AND folder != '' AND folder != '/' AND folder_id IS NULL"
    ));

    if ($needsMigration > 0) {
        $paths = [];
        $res = $db->query("SELECT DISTINCT folder FROM files WHERE folder IS NOT NULL AND folder != '/'");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $fp = $row['folder'];
            $parts = array_filter(explode('/', trim($fp, '/')));
            $built = '';
            foreach ($parts as $seg) {
                $built .= '/' . $seg;
                $paths[$built] = true;
            }
        }

        $pathToId = ['/' => null];
        ksort($paths);
        foreach (array_keys($paths) as $path) {
            $parts = array_filter(explode('/', trim($path, '/')));
            $name = end($parts);
            $parentPath = count($parts) > 1 ? '/' . implode('/', array_slice($parts, 0, -1)) : '/';
            $parentId = $pathToId[$parentPath] ?? null;
            $pathToId[$path] = getOrCreateFolder($db, $parentId, $name);
        }

        $res = $db->query("SELECT id, folder FROM files WHERE folder IS NOT NULL");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $fp = $row['folder'] ?: '/';
            $fid = ($fp === '/') ? null : ($pathToId[$fp] ?? null);
            $stmt = $db->prepare("UPDATE files SET folder_id = :fid WHERE id = :id");
            $stmt->bindValue(':fid', $fid, $fid === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':id', intval($row['id']));
            $stmt->execute();
        }
    }

    $db->exec("DELETE FROM files WHERE name = '.gitkeep'");
}

function normalizeFolderName($name) {
    $name = trim($name);
    $name = str_replace(['/', '\\'], '', $name);
    $name = preg_replace('/\s+/', '-', $name);
    return $name;
}

function parseFolderId($value) {
    if ($value === null || $value === '' || $value === 'null' || $value === 'root') {
        return null;
    }
    $id = intval($value);
    return $id > 0 ? $id : null;
}

function folderExists($db, $id) {
    if ($id === null) return true;
    $stmt = $db->prepare("SELECT id FROM folders WHERE id = :id");
    $stmt->bindValue(':id', $id);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) !== false;
}

function getFolderRow($db, $id) {
    if ($id === null) return null;
    $stmt = $db->prepare("SELECT * FROM folders WHERE id = :id");
    $stmt->bindValue(':id', $id);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

function getOrCreateFolder($db, $parentId, $name) {
    $name = normalizeFolderName($name);
    if ($name === '') return $parentId;

    $sql = "SELECT id FROM folders WHERE name = :name AND " .
           ($parentId === null ? "parent_id IS NULL" : "parent_id = :pid");
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':name', $name);
    if ($parentId !== null) $stmt->bindValue(':pid', $parentId);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) return intval($row['id']);

    $now = time();
    $stmt = $db->prepare(
        "INSERT INTO folders (name, parent_id, created_at, updated_at) VALUES (:name, :pid, :now, :now)"
    );
    $stmt->bindValue(':name', $name);
    if ($parentId === null) {
        $stmt->bindValue(':pid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':pid', $parentId);
    }
    $stmt->bindValue(':now', $now);
    $stmt->execute();
    return intval($db->lastInsertRowID());
}

function ensureFolderPath($db, $parentId, $relativePath) {
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') return $parentId;

    $currentId = $parentId;
    foreach (explode('/', $relativePath) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        $currentId = getOrCreateFolder($db, $currentId, $seg);
    }
    return $currentId;
}

function buildFolderPath($db, $folderId) {
    if ($folderId === null) return '/';
    $parts = [];
    $current = $folderId;
    $guard = 0;
    while ($current !== null && $guard++ < 100) {
        $row = getFolderRow($db, $current);
        if (!$row) break;
        array_unshift($parts, $row['name']);
        $current = $row['parent_id'] !== null ? intval($row['parent_id']) : null;
    }
    return $parts ? '/' . implode('/', $parts) : '/';
}

function buildBreadcrumb($db, $folderId) {
    $crumb = [['id' => null, 'name' => 'root']];
    if ($folderId === null) return $crumb;

    $chain = [];
    $current = $folderId;
    $guard = 0;
    while ($current !== null && $guard++ < 100) {
        $row = getFolderRow($db, $current);
        if (!$row) break;
        array_unshift($chain, ['id' => intval($row['id']), 'name' => $row['name']]);
        $current = $row['parent_id'] !== null ? intval($row['parent_id']) : null;
    }
    return array_merge($crumb, $chain);
}

function isDescendantFolder($db, $ancestorId, $candidateId) {
    if ($ancestorId === null || $candidateId === null) return false;
    if ($ancestorId === $candidateId) return true;

    $current = $candidateId;
    $guard = 0;
    while ($current !== null && $guard++ < 100) {
        $row = getFolderRow($db, $current);
        if (!$row) return false;
        if ($row['parent_id'] === null) return false;
        $parent = intval($row['parent_id']);
        if ($parent === $ancestorId) return true;
        $current = $parent;
    }
    return false;
}

function countFolderItems($db, $folderId) {
    $count = 0;
    if ($folderId === null) {
        $count += intval($db->querySingle("SELECT COUNT(*) FROM files WHERE folder_id IS NULL"));
        $count += intval($db->querySingle("SELECT COUNT(*) FROM folders WHERE parent_id IS NULL"));
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM files WHERE folder_id = :id");
        $stmt->bindValue(':id', $folderId);
        $count += intval($stmt->execute()->fetchArray(SQLITE3_NUM)[0]);
        $stmt = $db->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = :id");
        $stmt->bindValue(':id', $folderId);
        $count += intval($stmt->execute()->fetchArray(SQLITE3_NUM)[0]);
    }
    return $count;
}

function countSubtreeFiles($db, $folderId) {
    $total = 0;
    $queue = [$folderId];
    $guard = 0;
    while ($queue && $guard++ < 10000) {
        $fid = array_shift($queue);
        if ($fid === null) {
            $total += intval($db->querySingle("SELECT COUNT(*) FROM files WHERE folder_id IS NULL"));
            $res = $db->query("SELECT id FROM folders WHERE parent_id IS NULL");
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM files WHERE folder_id = :id");
            $stmt->bindValue(':id', $fid);
            $total += intval($stmt->execute()->fetchArray(SQLITE3_NUM)[0]);
            $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = :id");
            $stmt->bindValue(':id', $fid);
            $res = $stmt->execute();
        }
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $queue[] = intval($row['id']);
        }
    }
    return $total;
}

function enrichFileRow($db, $row) {
    $fid = isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== ''
        ? intval($row['folder_id']) : null;
    $row['folder_id'] = $fid;
    $row['folder'] = buildFolderPath($db, $fid);
    return $row;
}

function deleteFolderRecursive($db, $folderId) {
    $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = :id");
    $stmt->bindValue(':id', $folderId);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        deleteFolderRecursive($db, intval($row['id']));
    }

    $stmt = $db->prepare("DELETE FROM files WHERE folder_id = :id");
    $stmt->bindValue(':id', $folderId);
    $stmt->execute();

    $stmt = $db->prepare("DELETE FROM folders WHERE id = :id");
    $stmt->bindValue(':id', $folderId);
    $stmt->execute();
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function validateSession() {
    $token = '';
    if (!empty($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        $token = $_SERVER['HTTP_X_SESSION_TOKEN'];
    }
    if (empty($token) && function_exists('getallheaders')) {
        foreach ((getallheaders() ?: []) as $k => $v) {
            if (strtolower(trim($k)) === 'x-session-token') {
                $token = trim($v); break;
            }
        }
    }
    if (empty($token)) $token = $_GET['token'] ?? '';
    global $body;
    if (empty($token) && !empty($body['token'])) $token = $body['token'];
    if (empty($token)) return false;

    $db   = getDB();
    $stmt = $db->prepare("SELECT token FROM sessions WHERE token = :token AND expires_at > :now");
    $stmt->bindValue(':token', $token);
    $stmt->bindValue(':now',   time());
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $result !== false;
}

function jsonResponse($data, $code = 200) {
    while (ob_get_level() > 0) { ob_end_clean(); }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    exit();
}

function resolveFolderIdFromRequest($body) {
    if (array_key_exists('folder_id', $body)) {
        return parseFolderId($body['folder_id']);
    }
    if (!empty($body['folder_b64'])) {
        $path = base64_decode($body['folder_b64']);
        if ($path && $path !== '/') {
            $db = getDB();
            return ensureFolderPath($db, null, ltrim($path, '/'));
        }
        return null;
    }
    if (isset($body['folder'])) {
        $path = $body['folder'] ?: '/';
        if ($path === '/') return null;
        $db = getDB();
        return ensureFolderPath($db, null, ltrim($path, '/'));
    }
    if (isset($_GET['folder_id'])) {
        return parseFolderId($_GET['folder_id']);
    }
    return null;
}

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// LOGIN
if ($action === 'login') {
    $username = $body['username'] ?? null;
    $password = $body['password'] ?? null;
    if ($username === '' && $password === '') {
        $db    = getDB();
        $token = generateToken();
        $db->exec("DELETE FROM sessions WHERE expires_at < " . time());
        $stmt  = $db->prepare("INSERT INTO sessions (token, created_at, expires_at) VALUES (:token, :now, :exp)");
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':now',   time());
        $stmt->bindValue(':exp',   time() + 86400 * 7);
        $stmt->execute();
        jsonResponse(['success' => true, 'token' => $token]);
    }
    jsonResponse(['success' => false, 'message' => 'Sai tài khoản hoặc mật khẩu'], 401);
}

// LOGOUT
if ($action === 'logout') {
    $token = '';
    if (!empty($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        $token = $_SERVER['HTTP_X_SESSION_TOKEN'];
    } elseif (function_exists('getallheaders')) {
        foreach ((getallheaders() ?: []) as $k => $v) {
            if (strtolower(trim($k)) === 'x-session-token') { $token = trim($v); break; }
        }
    }
    if (!empty($token)) {
        $db   = getDB();
        $stmt = $db->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->bindValue(':token', $token);
        $stmt->execute();
    }
    jsonResponse(['success' => true]);
}

// AUTH CHECK
if ($action === 'check_auth') {
    jsonResponse(['authenticated' => validateSession()]);
}

if (!validateSession()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = getDB();

// LIST FOLDER CONTENTS (folders + files + breadcrumb)
if ($action === 'list_folder') {
    $folderId = resolveFolderIdFromRequest($body);
    if ($folderId !== null && !folderExists($db, $folderId)) {
        jsonResponse(['error' => 'Folder not found'], 404);
    }

    if ($folderId === null) {
        $fRes = $db->query("SELECT id, name, parent_id, created_at, updated_at FROM folders WHERE parent_id IS NULL ORDER BY name");
    } else {
        $stmt = $db->prepare("SELECT id, name, parent_id, created_at, updated_at FROM folders WHERE parent_id = :id ORDER BY name");
        $stmt->bindValue(':id', $folderId);
        $fRes = $stmt->execute();
    }

    $folders = [];
    while ($row = $fRes->fetchArray(SQLITE3_ASSOC)) {
        $id = intval($row['id']);
        $folders[] = [
            'id'          => $id,
            'name'        => $row['name'],
            'parent_id'   => $row['parent_id'] !== null ? intval($row['parent_id']) : null,
            'created_at'  => intval($row['created_at']),
            'updated_at'  => intval($row['updated_at']),
            'path'        => buildFolderPath($db, $id),
            'item_count'  => countFolderItems($db, $id),
            'file_count'  => countSubtreeFiles($db, $id),
        ];
    }

    if ($folderId === null) {
        $stmt = $db->prepare(
            "SELECT id, name, size, created_at, updated_at, folder_id, pinned, substr(content,1,200) as preview
             FROM files WHERE folder_id IS NULL ORDER BY pinned DESC, updated_at DESC"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT id, name, size, created_at, updated_at, folder_id, pinned, substr(content,1,200) as preview
             FROM files WHERE folder_id = :id ORDER BY pinned DESC, updated_at DESC"
        );
        $stmt->bindValue(':id', $folderId);
    }

    $files = [];
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $files[] = enrichFileRow($db, $row);
    }

    jsonResponse([
        'folder_id'  => $folderId,
        'folders'    => $folders,
        'files'      => $files,
        'breadcrumb' => buildBreadcrumb($db, $folderId),
    ]);
}

// LIST ALL FOLDERS (flat, for trees)
if ($action === 'list_all_folders') {
    $res = $db->query("SELECT id, name, parent_id, created_at, updated_at FROM folders ORDER BY name");
    $folders = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $id = intval($row['id']);
        $folders[] = [
            'id'         => $id,
            'name'       => $row['name'],
            'parent_id'  => $row['parent_id'] !== null ? intval($row['parent_id']) : null,
            'created_at' => intval($row['created_at']),
            'updated_at' => intval($row['updated_at']),
            'path'       => buildFolderPath($db, $id),
        ];
    }
    jsonResponse(['folders' => $folders]);
}

// CREATE FOLDER
if ($action === 'create_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = normalizeFolderName($body['name'] ?? '');
    $parentId = parseFolderId($body['parent_id'] ?? null);

    if ($name === '') jsonResponse(['error' => 'Name required'], 400);
    if ($parentId !== null && !folderExists($db, $parentId)) {
        jsonResponse(['error' => 'Parent folder not found'], 404);
    }

    $sql = "SELECT id FROM folders WHERE name = :name AND " .
           ($parentId === null ? "parent_id IS NULL" : "parent_id = :pid");
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':name', $name);
    if ($parentId !== null) $stmt->bindValue(':pid', $parentId);
    if ($stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
        jsonResponse(['error' => 'Folder already exists'], 409);
    }

    $now = time();
    $stmt = $db->prepare(
        "INSERT INTO folders (name, parent_id, created_at, updated_at) VALUES (:name, :pid, :now, :now)"
    );
    $stmt->bindValue(':name', $name);
    if ($parentId === null) {
        $stmt->bindValue(':pid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':pid', $parentId);
    }
    $stmt->bindValue(':now', $now);
    $stmt->execute();
    $id = intval($db->lastInsertRowID());

    jsonResponse([
        'success' => true,
        'folder'  => [
            'id'        => $id,
            'name'      => $name,
            'parent_id' => $parentId,
            'path'      => buildFolderPath($db, $id),
        ],
    ]);
}

// RENAME FOLDER
if ($action === 'rename_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($body['id'] ?? 0);
    $name = normalizeFolderName($body['name'] ?? '');
    if (!$id || $name === '') jsonResponse(['error' => 'Invalid params'], 400);

    $row = getFolderRow($db, $id);
    if (!$row) jsonResponse(['error' => 'Not found'], 404);

    $parentId = $row['parent_id'] !== null ? intval($row['parent_id']) : null;
    $sql = "SELECT id FROM folders WHERE name = :name AND id != :id AND " .
           ($parentId === null ? "parent_id IS NULL" : "parent_id = :pid");
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':id', $id);
    if ($parentId !== null) $stmt->bindValue(':pid', $parentId);
    if ($stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
        jsonResponse(['error' => 'Name already used in this folder'], 409);
    }

    $stmt = $db->prepare("UPDATE folders SET name = :name, updated_at = :now WHERE id = :id");
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':now', time());
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    jsonResponse(['success' => true, 'path' => buildFolderPath($db, $id)]);
}

// DELETE FOLDER (recursive)
if ($action === 'delete_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($body['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Invalid id'], 400);
    if (!getFolderRow($db, $id)) jsonResponse(['error' => 'Not found'], 404);

    deleteFolderRecursive($db, $id);
    jsonResponse(['success' => true]);
}

// MOVE FOLDER
if ($action === 'move_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($body['id'] ?? 0);
    $targetParentId = parseFolderId($body['target_parent_id'] ?? null);

    if (!$id) jsonResponse(['error' => 'Invalid id'], 400);
    $row = getFolderRow($db, $id);
    if (!$row) jsonResponse(['error' => 'Not found'], 404);
    if ($targetParentId !== null && !folderExists($db, $targetParentId)) {
        jsonResponse(['error' => 'Target folder not found'], 404);
    }
    if ($targetParentId === $id || isDescendantFolder($db, $id, $targetParentId)) {
        jsonResponse(['error' => 'Cannot move folder into itself or its descendant'], 400);
    }

    $parentId = $row['parent_id'] !== null ? intval($row['parent_id']) : null;
    if ($parentId === $targetParentId) {
        jsonResponse(['success' => true, 'moved' => 0]);
    }

    $sql = "SELECT id FROM folders WHERE name = :name AND id != :id AND " .
           ($targetParentId === null ? "parent_id IS NULL" : "parent_id = :pid");
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':name', $row['name']);
    $stmt->bindValue(':id', $id);
    if ($targetParentId !== null) $stmt->bindValue(':pid', $targetParentId);
    if ($stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
        jsonResponse(['error' => 'A folder with this name already exists in destination'], 409);
    }

    $stmt = $db->prepare("UPDATE folders SET parent_id = :pid, updated_at = :now WHERE id = :id");
    if ($targetParentId === null) {
        $stmt->bindValue(':pid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':pid', $targetParentId);
    }
    $stmt->bindValue(':now', time());
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    jsonResponse(['success' => true, 'path' => buildFolderPath($db, $id)]);
}

// ENSURE NESTED FOLDERS (for uploads)
if ($action === 'ensure_folder_path' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parentId = parseFolderId($body['parent_id'] ?? null);
    $relative = $body['relative_path'] ?? '';
    if ($parentId !== null && !folderExists($db, $parentId)) {
        jsonResponse(['error' => 'Parent folder not found'], 404);
    }
    $folderId = ensureFolderPath($db, $parentId, $relative);
    jsonResponse([
        'success'   => true,
        'folder_id' => $folderId,
        'path'      => buildFolderPath($db, $folderId),
    ]);
}

// LIST FILES (legacy — files only, no subfolders)
if ($action === 'list_files') {
    $folderId = resolveFolderIdFromRequest($body);
    if ($folderId !== null && !folderExists($db, $folderId)) {
        jsonResponse(['error' => 'Folder not found'], 404);
    }
    if ($folderId === null) {
        $stmt = $db->prepare(
            "SELECT id, name, size, created_at, updated_at, folder_id, pinned, substr(content,1,200) as preview
             FROM files WHERE folder_id IS NULL ORDER BY pinned DESC, updated_at DESC"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT id, name, size, created_at, updated_at, folder_id, pinned, substr(content,1,200) as preview
             FROM files WHERE folder_id = :id ORDER BY pinned DESC, updated_at DESC"
        );
        $stmt->bindValue(':id', $folderId);
    }
    $files = [];
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $files[] = enrichFileRow($db, $row);
    }
    jsonResponse(['files' => $files]);
}

// GET FILE
if ($action === 'get_file') {
    $id   = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM files WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $file = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$file) jsonResponse(['error' => 'Not found'], 404);
    jsonResponse(['file' => enrichFileRow($db, $file)]);
}

// CREATE FILE
if ($action === 'create_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($body['name'] ?? '');
    $content  = $body['content'] ?? '';
    $folderId = parseFolderId($body['folder_id'] ?? null);

    if (empty($name)) jsonResponse(['error' => 'Name required'], 400);
    if ($folderId !== null && !folderExists($db, $folderId)) {
        jsonResponse(['error' => 'Folder not found'], 404);
    }

    $now = time();
    $stmt = $db->prepare(
        "INSERT INTO files (name, content, size, created_at, updated_at, folder_id, folder)
         VALUES (:name, :content, :size, :now, :now, :fid, :fpath)"
    );
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':content', $content);
    $stmt->bindValue(':size', strlen($content));
    $stmt->bindValue(':now', $now);
    if ($folderId === null) {
        $stmt->bindValue(':fid', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':fid', $folderId);
    }
    $stmt->bindValue(':fpath', buildFolderPath($db, $folderId));
    $stmt->execute();
    jsonResponse(['success' => true, 'id' => $db->lastInsertRowID()]);
}

// UPDATE FILE
if ($action === 'update_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = intval($body['id'] ?? 0);
    $content = $body['content'] ?? '';
    $name    = $body['name'] ?? null;
    $db      = getDB();
    if ($name !== null) {
        $stmt = $db->prepare("UPDATE files SET content=:content, name=:name, size=:size, updated_at=:now WHERE id=:id");
        $stmt->bindValue(':name', $name);
    } else {
        $stmt = $db->prepare("UPDATE files SET content=:content, size=:size, updated_at=:now WHERE id=:id");
    }
    $stmt->bindValue(':content', $content);
    $stmt->bindValue(':size',    strlen($content));
    $stmt->bindValue(':now',     time());
    $stmt->bindValue(':id',      $id);
    $stmt->execute();
    jsonResponse(['success' => true]);
}

// DELETE FILE
if ($action === 'delete_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = intval($body['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM files WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    jsonResponse(['success' => true]);
}

// PIN FILE
if ($action === 'pin_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = intval($body['id'] ?? 0);
    $pinned = intval($body['pinned'] ?? 0);
    $stmt   = $db->prepare("UPDATE files SET pinned=:pinned WHERE id=:id");
    $stmt->bindValue(':pinned', $pinned);
    $stmt->bindValue(':id',     $id);
    $stmt->execute();
    jsonResponse(['success' => true]);
}

// UPLOAD FILE
if ($action === 'upload_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file'])) {
        $name = $_FILES['file']['name'];
        $content = file_get_contents($_FILES['file']['tmp_name']);

        $folderId = null;
        if (array_key_exists('folder_id', $_POST)) {
            $folderId = parseFolderId($_POST['folder_id']);
        } elseif (!empty($_POST['folder_relative'])) {
            $baseParent = parseFolderId($_POST['parent_id'] ?? null);
            $folderId = ensureFolderPath($db, $baseParent, $_POST['folder_relative']);
        } elseif (array_key_exists('parent_id', $_POST)) {
            $folderId = parseFolderId($_POST['parent_id']);
        } elseif (!empty($_POST['folder']) && $_POST['folder'] !== '/') {
            $folderId = ensureFolderPath($db, null, ltrim($_POST['folder'], '/'));
        }

        if ($folderId !== null && !folderExists($db, $folderId)) {
            jsonResponse(['error' => 'Folder not found'], 404);
        }

        $now = time();
        $stmt = $db->prepare(
            "INSERT INTO files (name, content, size, created_at, updated_at, folder_id, folder)
             VALUES (:name, :content, :size, :now, :now, :fid, :fpath)"
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':content', $content);
        $stmt->bindValue(':size', strlen($content));
        $stmt->bindValue(':now', $now);
        if ($folderId === null) {
            $stmt->bindValue(':fid', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':fid', $folderId);
        }
        $stmt->bindValue(':fpath', buildFolderPath($db, $folderId));
        $stmt->execute();
        jsonResponse(['success' => true, 'id' => $db->lastInsertRowID()]);
    }
    jsonResponse(['error' => 'No file'], 400);
}

// DOWNLOAD FILE
if ($action === 'download_file') {
    $id   = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM files WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $file = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$file) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(404); echo 'Not found'; exit();
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($file['name']) . '"');
    echo $file['content'];
    exit();
}

// LIST ALL FILES
if ($action === 'list_all_files') {
    $res = $db->query(
        "SELECT id, name, size, created_at, updated_at, folder_id, pinned FROM files ORDER BY folder_id, name"
    );
    $files = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $files[] = enrichFileRow($db, $row);
    }
    jsonResponse(['files' => $files]);
}

// MOVE FILES
if ($action === 'move_files' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $body['ids'] ?? [];
    $targetFolderId = parseFolderId($body['target_folder_id'] ?? null);

    if ($targetFolderId !== null && !folderExists($db, $targetFolderId)) {
        jsonResponse(['error' => 'Target folder not found'], 404);
    }

    $moved = 0;
    $fpath = buildFolderPath($db, $targetFolderId);
    foreach ($ids as $id) {
        $stmt = $db->prepare(
            "UPDATE files SET folder_id = :fid, folder = :fpath, updated_at = :now WHERE id = :id"
        );
        if ($targetFolderId === null) {
            $stmt->bindValue(':fid', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':fid', $targetFolderId);
        }
        $stmt->bindValue(':fpath', $fpath);
        $stmt->bindValue(':now', time());
        $stmt->bindValue(':id', intval($id));
        $stmt->execute();
        $moved++;
    }
    jsonResponse(['success' => true, 'moved' => $moved]);
}

// LIST FILES IN FOLDER SUBTREE (for download zip)
if ($action === 'list_files_in_folder') {
    $folderId = parseFolderId($_GET['folder_id'] ?? $body['folder_id'] ?? null);
    if ($folderId !== null && !folderExists($db, $folderId)) {
        jsonResponse(['error' => 'Folder not found'], 404);
    }

    $allFolderIds = [];
    if ($folderId === null) {
        $allFolderIds[] = null;
        $res = $db->query("SELECT id FROM folders");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $allFolderIds[] = intval($row['id']);
        }
    } else {
        $queue = [$folderId];
        $guard = 0;
        while ($queue && $guard++ < 10000) {
            $fid = array_shift($queue);
            $allFolderIds[] = $fid;
            $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = :id");
            $stmt->bindValue(':id', $fid);
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $queue[] = intval($row['id']);
            }
        }
    }

    $files = [];
    foreach ($allFolderIds as $fid) {
        if ($fid === null) {
            $stmt = $db->prepare("SELECT id, name, folder_id FROM files WHERE folder_id IS NULL");
        } else {
            $stmt = $db->prepare("SELECT id, name, folder_id FROM files WHERE folder_id = :id");
            $stmt->bindValue(':id', $fid);
        }
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $files[] = enrichFileRow($db, $row);
        }
    }

    $rootPath = buildFolderPath($db, $folderId);
    jsonResponse(['files' => $files, 'root_path' => $rootPath]);
}

jsonResponse(['error' => 'Unknown action'], 400);
