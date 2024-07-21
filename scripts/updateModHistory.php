<?php

$modHistoryFile = 'fileModHistory.php';

$modHistoryArray = [];
if (file_exists($modHistoryFile)) {
    echo timeStamp() . " - Loading modification history file... ";
    $modHistoryArray = include $modHistoryFile;
    if (!is_array($modHistoryArray)) {
		echo "file is corrupted (not an array)\n";
        exit(1);
    }
    echo "done\n";
} else {
    echo timeStamp() . " - Modification history file doesn't exist\n";
}

if (isset($modHistoryArray["last commit hash"]) && $modHistoryArray["last commit hash"] !== "") {
    echo timeStamp() . " - Retrieving hash of the common ancestor of HEAD and the last commit... ";
    $cmd = "git merge-base " . $modHistoryArray["last commit hash"] . "\$GITHUB_SHA";
    if (exec($cmd, $commonAncestor) === false) {
		echo "failed\n";
        exit(1);
    }
    $commonAncestorHash = implode("", $commonAncestor);
    echo "done: ";
} else {
    echo timeStamp() . " - Last commit hash not found. Using empty git tree hash: ";
    // since there is no modification history, generate it for all commits since the inital one
    // 4b825dc642cb6eb9a060e54bf8d69288fbee4904 is the SHA1 of the empty git tree
    $commonAncestorHash = "4b825dc642cb6eb9a060e54bf8d69288fbee4904";
}
echo $commonAncestorHash . "\n";

$modifiedFilescommand = <<<COMMAND
#!/usr/bin/env bash
echo "last commit hash:"
echo "$(git rev-parse HEAD)"
git diff --name-only $commonAncestorHash \$GITHUB_SHA | while read -r filename; do
  echo "filename:"
  echo "\$filename"
  echo "modified:"
  echo "$(git log -1 --format='%aI' -- \$filename)"
  echo "contributors:"
  git log --format='%an' -- \$filename|awk '!a[$0]++'
done
COMMAND;

echo timeStamp() . " - Retrieving commit authors and last commit date/time of modified files... \n";

$numOfFilesInDir = iterator_count(
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            realpath(__DIR__),
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
)); // TODO: remove . and .. from count
$fileCounter = 0;

$modifiedFiles = [];

$proc = popen($modifiedFilescommand, 'rb');
//while (($line = stream_get_line($proc, 65535, "\n")) !== false) {
while (($line = fgets($proc)) !== false) {
    processGitDiffLine(rtrim($line, "\n\r"), $modifiedFiles);
    fwrite(
        STDERR, 
        sprintf("\033[0G{$fileCounter} of {$numOfFilesInDir} files read", "", "")
    );
}
pclose($proc);
    
echo "done\n";

echo timeStamp() . " - Number of files modified since last commit: " . (count($modifiedFiles) - 1) . "\n";
if (count($modifiedFiles) === 1) {
    // there will always be at least 1 entry with the last commit hash
    exit(1);
}

$mergedModHistory = array_merge($modHistoryArray, $modifiedFiles);
    
echo timeStamp() . " - Writing modification history file... ";

$fp = fopen($modHistoryFile, "w");
fwrite($fp, "<?php\n\n/* This is a generated file */\n\nreturn [\n");
foreach ($mergedModHistory as $fileName => $fileProps) {
    if ($fileName === "last commit hash") {
        fwrite($fp, "    \"last commit hash\" => \"" . implode("", $fileProps) . "\",\n");
        continue;
    }
    $newModHistoryString = '    "' . $fileName . "\" => [\n";
    $newModHistoryString .= "        \"modified\" => \"" . ($fileProps["modified"] ?? "") . "\",\n";
    $newModHistoryString .= "        \"contributors\" => [\n";
    if (isset($fileProps["contributors"])) {
        if (!is_array($fileProps["contributors"])) {
            exit("Non-array contributors list\n");
        }
        foreach ($fileProps["contributors"] as $contributor) {
            $newModHistoryString .= "            \"" . $contributor . "\",\n";
        }
    }
    $newModHistoryString .= "        ],\n";
    $newModHistoryString .= "    ],\n";
    fwrite($fp, $newModHistoryString);
}
fwrite($fp, "];\n");
fclose($fp);

echo "done at " . date('H:i:s') . "\n";

function timeStamp(): string {
    return "[" . date('H:i:s') . "]";
}

function processGitDiffLine($line, &$modifiedFiles): void {
    static $currentType = "";
    static $currentFile = "";
    global $fileCounter;
    
    switch ($line) {
        case "filename:":
            $currentType = "filename";
            $fileCounter++;
            return;
        case "modified:":
            $currentType = "modDateTime";
            return;
        case "contributors:":
            $currentType = "contributors";
            return;
        case "last commit hash:":
            $currentType = "commitHash";
            return;
    }
    if ($currentType === "") {
        return;
    }

    switch ($currentType) {
        case "filename":
            $currentFile = $line;
            break;
        case "modDateTime":
            if ($currentFile === "") {
                return;
            }
            $modifiedFiles[$currentFile]["modified"] = $line;
            break;
        case "contributors":
            if ($currentFile === "") {
                return;
            }
            $modifiedFiles[$currentFile]["contributors"][] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
            break;
        case "commitHash":
            $modifiedFiles["last commit hash"][] = $line;
            break;
    }
}
