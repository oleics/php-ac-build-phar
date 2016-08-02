#!/usr/bin/env php
<?php

function usage($msg = null) {
  if($msg) {
    echo "$msg\n";
  }
  echo "Usage: php -dphar.readonly=0 build-phar.php phar-filename [main-filename]\n";
  die();
}

//
if(ini_get('phar.readonly')) usage('Missing php-option "phar.readonly=1"');

//
if(empty($argv[1])) usage('Missing argument "phar-filename".');
$pharFilename = basename($argv[1]);
$buildFolder = dirname($argv[1]);
if(!is_dir($buildFolder)) {
  mkdir($buildFolder);
}
if(!is_dir($buildFolder)) {
  die('Must be a directory: '.$buildFolder);
}
$buildFolder = realpath($buildFolder);
$buildFile = $buildFolder.'/'.$pharFilename;

//
if(!empty($argv[2])) {
  $mainFilename = $argv[2];
} else {
  $mainFilename = false;
}

//
$sourceFolder = getcwd();

$sourceExclude = array('.git','build-phar.php');
$filenameInclude = '*.php';
$filenameExclude = '.git';

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
$stub = <<<'EOF'
#!/usr/bin/env php
<?php

EOF;
$stub .= "if(time()-".time()." > 1.21e+6) echo \"This version of '$pharFilename' is older than 14 days!\\n\";\n";
$stub .= "Phar::mapPhar('$pharFilename');\n";
if($mainFilename) {
  echo "main: $mainFilename\n";
  $stub .= "require 'phar://$pharFilename/$mainFilename';\n";
}
$stub .= "__HALT_COMPILER();\n";
// echo "$stub\n";
$phar->setStub($stub);

$phar->stopBuffering();

echo "OK '$pharFilename' created in '$buildFolder'\n";
exit(0);
