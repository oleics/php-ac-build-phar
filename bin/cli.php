#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Ac\Minimist;

function usage($msg = null) {
  if($msg) {
    echo "$msg\n";
  }
  echo "Usage: php -dphar.readonly=0 ".$GLOBALS['argv'][0]." [--phar phar-filename] [--require main-filename] [--license license-file] [--copyright copyright-file]\n";
  die();
}

$opts = (object) Minimist::parse($argv, [
  'string' => [
    'source',
    'phar',
    'require',
    'license',
    'copyright'
  ],
  'default' => [
    'source' => function() {
      return getcwd();
    },
    'phar' => function() {
      if(file_exists('composer.json')) {
        $name = @json_decode(file_get_contents('composer.json'))->name;
        $name = @preg_replace('/\W/', '', basename($name));
        if(empty($name)) return false;
      }
      return $name.'.phar';
    },
  ],
]);

print_r($opts);
// exit(0);

//
if(ini_get('phar.readonly')) usage('Missing php-option "phar.readonly=1"');

//
if(!$opts->phar) usage('Missing option "--phar".');
$pharFilename = basename($opts->phar);
$buildFolder = dirname($opts->phar);
if(!is_dir($buildFolder)) {
  mkdir($buildFolder);
}
if(!is_dir($buildFolder)) {
  die('Must be a directory: '.$buildFolder);
}
$buildFolder = realpath($buildFolder);
$buildFile = $buildFolder.'/'.$pharFilename;

//
if($opts->require) {
  $mainFilename = $opts->require;
} else {
  $mainFilename = false;
}

//
if(!$opts->source) usage('Missing option "--source".');
$sourceFolder = $opts->source;

$sourceExclude = array('.git','build-phar.php');
$filenameInclude = '*.php';
$filenameExclude = '.git';

//
if($opts->license) {
  if(!file_exists($opts->license)) {
    usage("License-file \"$opts->license\" not found.");
  }
  $license = file_get_contents($opts->license);
} else {
  $license = false;
}
if($opts->copyright) {
  if(!file_exists($opts->copyright)) {
    usage("Copyright-file \"$opts->copyright\" not found.");
  }
  $copyright = file_get_contents($opts->copyright);
} else {
  $copyright = false;
}

//
echo "pharFilename : $pharFilename\n";
echo "mainFilename : $mainFilename\n";
echo "\n";
echo "buildFolder : $buildFolder\n";
echo "buildFile   : $buildFile\n";
echo "\n";
echo "sourceFolder    : $sourceFolder\n";
echo "sourceExclude   : ".join(' ', $sourceExclude)."\n";
echo "filenameInclude : $filenameInclude\n";
echo "filenameExclude : $filenameExclude\n";
echo "\n";

//
echo "Creating '$pharFilename' in '$buildFolder' ...\n";

//
if(is_file($buildFile)) {
  unlink($buildFile);
}

//
$phar = new Phar($buildFile, 0, $pharFilename);
$phar->setSignatureAlgorithm(\Phar::SHA1);
$phar->startBuffering();

//
$linkedRealPaths = [];
$paths = [];
$iter = new RecursiveIteratorIterator(
  new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator(
      $sourceFolder,
      FilesystemIterator::UNIX_PATHS |
      FilesystemIterator::SKIP_DOTS |
      FilesystemIterator::FOLLOW_SYMLINKS |
      RecursiveDirectoryIterator::FOLLOW_SYMLINKS
    ),
    function($fileInfo, $key, $iterator) use(&$sourceExclude, &$filenameInclude, &$filenameExclude, &$linkedRealPaths) {
      // if(!$fileInfo->isFile()) return false;
      if(substr($fileInfo->getBasename(), 0, 1) === '.') return false;
      if(in_array($fileInfo->getBasename(), $sourceExclude)) return false;
      if(fnmatch($filenameExclude, $fileInfo->getBasename())) return false;
      if($fileInfo->isLink()) {
        $realPath = $fileInfo->getRealPath();
        if(array_search($realPath, $linkedRealPaths) !== false) return false;
        echo '  LINK ! '.$fileInfo->getRealPath()."\n";
        $linkedRealPaths[] = $realPath;
      }
      if($fileInfo->isDir()) return true;
      return fnmatch($filenameInclude, $fileInfo->getBasename());
    }
  )
);
foreach($iter as $p) {
  if(DIRECTORY_SEPARATOR !== '/') {
    $p = str_replace(DIRECTORY_SEPARATOR, '/', $p);
  }
  $pLocal = str_replace($sourceFolder.'/', '', $p);
  echo '~ '.$p.'';
  $phar->addFile($p, $pLocal);
  echo "\r+ ".$p."\n";
}

//
$stub = "#!/usr/bin/env php\n<?php\n";

if($copyright) {
  $stub .= "\n/** $pharFilename \n".$copyright."\n */\n";
}

if($license) {
  $stub .= "\n/** $pharFilename \n".$license."\n */\n";
}

$stub .= "if(time()-".time()." > 1.21e+6) echo \"This version of '$pharFilename' is older than 14 days!\\n\";\n";
$stub .= "Phar::mapPhar('$pharFilename');\n";
if($mainFilename) {
  echo "main: $mainFilename\n";
  $stub .= "require 'phar://$pharFilename/$mainFilename';\n";
}
$stub .= "__HALT_COMPILER();\n";
echo "$stub\n";
$phar->setStub($stub);

$phar->stopBuffering();

echo "OK '$pharFilename' created in '$buildFolder'\n";
exit(0);
