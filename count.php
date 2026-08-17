<?php
declare(strict_types=1);

// がうんたー by さこつ

// 設定
const FILE_NAME = 'count.db'; // カウントを保存するデータベース名
const MINIMUM_DIGITS = 6; // カウントの桁数の最小値
const IMAGE_DIRECTORY = 'iamge'; // 数字画像を保存したディレクトリ

try {
  $pdo = init();
  $counts = countAccess($pdo);

  $mode = filter_input_data('POST', 'mode');
  $mode = is_string($mode) && $mode !== ''
    ? $mode
    : filter_input_data('GET', 'mode');

  $value = match ($mode) {
    'today' => $counts['today'],
    'yesterday' => $counts['yesterday'],
    default => $counts['total'],
  };

  outputCounterImage($value);
} catch (Throwable $exception) {
  error_log('counter: ' . $exception->getMessage());

  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
  }

  echo 'Failed to create the access counter image.';
}

/**
 * データベースを初期化して接続を返す。
 */
function init(): PDO {
  $pdo = new PDO('sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . FILE_NAME);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA busy_timeout = 5000');

  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS counts (
      id INTEGER PRIMARY KEY AUTOINCREMENT, -- ID
      total INTEGER NOT NULL DEFAULT 0, -- 累計カウンター
      today INTEGER NOT NULL DEFAULT 0, -- 今日の
      yesterday INTEGER NOT NULL DEFAULT 0, -- 昨日の
      host TEXT NOT NULL DEFAULT \'\', -- 連続カウント防止用host
      last_date TEXT NOT NULL DEFAULT \'\' -- 昨日の日付
    )'
  );

  $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
  try {
    migrateDatabase($pdo);

    $rowExists = (bool) $pdo->query('SELECT 1 FROM counts LIMIT 1')->fetchColumn();
    if (!$rowExists) {
      $statement = $pdo->prepare(
        'INSERT INTO counts (total, today, yesterday, host, last_date) VALUES (0, 0, 0, :host, :last_date)'
      );
      $statement->execute([
        ':host' => '',
        ':last_date' => date('Y-m-d'),
      ]);
    }

    $pdo->exec('COMMIT');
  } catch (Throwable $exception) {
    $pdo->exec('ROLLBACK');
    throw $exception;
  }

  return $pdo;
}

/**
 * 初期版のデータベースにも日付管理用の列を追加する。
 */
function migrateDatabase(PDO $pdo): void
{
  $columns = $pdo->query('PRAGMA table_info(counts)')->fetchAll();
  $columnNames = array_column($columns, 'name');

  if (!in_array('last_date', $columnNames, true)) {
    $pdo->exec("ALTER TABLE counts ADD COLUMN last_date TEXT NOT NULL DEFAULT ''");

    // 日付情報がなかった既存カウントは、移行した当日の値として引き継ぐ。
    $statement = $pdo->prepare(
      'UPDATE counts SET last_date = :last_date WHERE last_date = :empty_date'
    );
    $statement->execute([
      ':last_date' => date('Y-m-d'),
      ':empty_date' => '',
    ]);
  }
}

/**
 * 日付を更新し、直前と異なる接続元からのアクセスだけを加算する。
 *
 * @return array{total: int, today: int, yesterday: int}
 */
function countAccess(PDO $pdo): array
{
  $transactionStarted = false;

  try {
    // 最初から書き込みロックを取って、同時アクセス時の加算漏れを防ぐ。
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    $transactionStarted = true;

    $row = $pdo->query(
      'SELECT id, total, today, yesterday, host, last_date FROM counts ORDER BY id LIMIT 1'
    )->fetch();

    if ($row === false) {
      throw new RuntimeException('The counter row does not exist.');
    }

    $todayDate = date('Y-m-d');
    $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
    $storedDate = (string) $row['last_date'];

    $total = max(0, (int) $row['total']);
    $today = max(0, (int) $row['today']);
    $yesterday = max(0, (int) $row['yesterday']);
    $lastHost = (string) $row['host'];

    if ($storedDate !== $todayDate) {
      $yesterday = $storedDate === $yesterdayDate ? $today : 0;
      $today = 0;
      $lastHost = '';
    }

    $host = getClientHost();
    if ($host === '' || !hash_equals($lastHost, $host)) {
      ++$total;
      ++$today;
      $lastHost = $host;
    }

    $statement = $pdo->prepare(
      'UPDATE counts SET total = :total, today = :today, yesterday = :yesterday, host = :host, last_date = :last_date WHERE id = :id'
    );
    $statement->execute([
      ':total' => $total,
      ':today' => $today,
      ':yesterday' => $yesterday,
      ':host' => $lastHost,
      ':last_date' => $todayDate,
      ':id' => (int) $row['id'],
    ]);

    $pdo->exec('COMMIT');
    $transactionStarted = false;

    return [
      'total' => $total,
      'today' => $today,
      'yesterday' => $yesterday,
    ];
  } catch (Throwable $exception) {
    if ($transactionStarted) {
      $pdo->exec('ROLLBACK');
    }

    throw $exception;
  }
}

/**
 * 連続アクセス判定に使う接続元アドレスを返す。
 */
function getClientHost(): string
{
  $host = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!is_string($host)) {
    return '';
  }

  return substr($host, 0, 255);
}

/**
 * カウントを数字画像として連結して出力する。
 */
function outputCounterImage(int $count): void
{
  $digits = str_pad((string) max(0, $count), MINIMUM_DIGITS, '0', STR_PAD_LEFT);
  $images = [];
  $width = 0;
  $height = 0;

  try {
    foreach (str_split($digits) as $digit) {
      $path = __DIR__ . DIRECTORY_SEPARATOR . IMAGE_DIRECTORY
        . DIRECTORY_SEPARATOR . $digit . '.png';
      $image = imagecreatefrompng($path);

      if ($image === false) {
        throw new RuntimeException('Failed to load digit image: ' . $path);
      }

      $images[] = $image;
      $width += imagesx($image);
      $height = max($height, imagesy($image));
    }

    $counterImage = imagecreatetruecolor($width, $height);
    if ($counterImage === false) {
      throw new RuntimeException('Failed to create the counter image.');
    }

    imagealphablending($counterImage, false);
    imagesavealpha($counterImage, true);
    $transparent = imagecolorallocatealpha($counterImage, 0, 0, 0, 127);
    imagefill($counterImage, 0, 0, $transparent);
    imagealphablending($counterImage, true);

    $x = 0;
    foreach ($images as $image) {
      imagecopy(
        $counterImage,
        $image,
        $x,
        0,
        0,
        0,
        imagesx($image),
        imagesy($image)
      );
      $x += imagesx($image);
    }

    if (!headers_sent()) {
      header('Content-Type: image/png');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
    }

    if (!imagepng($counterImage)) {
      throw new RuntimeException('Failed to output the counter image.');
    }
  } finally {
    foreach ($images as $image) {
      imagedestroy($image);
    }

    if (isset($counterImage) && $counterImage instanceof GdImage) {
      imagedestroy($counterImage);
    }
  }
}

/**
 * filter_input のラッパー関数。
 *
 * @return mixed
 */
function filter_input_data(string $input, string $key, int $filter = FILTER_DEFAULT)
{
  $value = match ($input) {
    'GET' => $_GET[$key] ?? null,
    'POST' => $_POST[$key] ?? null,
    'COOKIE' => $_COOKIE[$key] ?? null,
    default => null,
  };

  if ($value === null || is_array($value)) {
    return null;
  }

  return match ($filter) {
    FILTER_VALIDATE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    FILTER_VALIDATE_INT => filter_var($value, FILTER_VALIDATE_INT),
    FILTER_VALIDATE_URL => filter_var($value, FILTER_VALIDATE_URL),
    default => $value,
  };
}
