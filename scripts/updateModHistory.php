<?php

$modHistoryFile = 'fileModHistory.php';

$modHistoryArray = [];
if (file_exists($modHistoryFile)) {
    echo "Loading modification history file... ";
    $modHistoryArray = include $modHistoryFile;
    if (!is_array($modHistoryArray)) {
		echo "file is corrupted (not an array)\n";
        exit(1);
    }
    echo "done\n";
} else {
    echo "Modification history file doesn't exist\n";
}

if (isset($modHistoryArray["last commit hash"]) && $modHistoryArray["last commit hash"] !== "") {
    $cmd = "git rev-parse --quiet --verify " . $modHistoryArray["last commit hash"];
	echo "Verifying last commit hash... ";
    if (exec($cmd, $verifiedHash) === false) {
		echo "failed\n";
        exit(1);
    }
    echo "done: " . implode("", $verifiedHash) . "\n";
	
	echo "Verifying last commit hash is in the master branch's commit history... ";
    if (implode("", $verifiedHash) !== $modHistoryArray["last commit hash"]) {
        // we cannot handle reverted commits as we don't know what changes to roll back
		echo "failed\n";
        exit(1);
    }
	echo "done\n";
    $lastCommitHash = $modHistoryArray["last commit hash"];
} else {
    echo "Last commit hash not found: using empty git tree hash\n";
    // since there is no modification history, generate it for all commits since the inital one
    // 4b825dc642cb6eb9a060e54bf8d69288fbee4904 is the SHA1 of the empty git tree
    $lastCommitHash = "4b825dc642cb6eb9a060e54bf8d69288fbee4904";
}

$modifiedFilescommand = <<<COMMAND
#!/usr/bin/env bash
echo "last commit hash:"
echo "$(git rev-parse HEAD)"
git diff $(git merge-base $lastCommitHash master) HEAD --name-only | while read -r filename; do
# git diff --name-only HEAD $lastCommitHash | while read -r filename; do
  echo "filename:"
  echo "\$filename"
  echo "modified:"
  echo "$(git log -1 --format='%aI' -- \$filename)"
  echo "contributors:"
  git log --format='%an' -- \$filename|awk '!a[$0]++'
done
COMMAND;

echo "Getting info on modified files... ";
fflush(\STDOUT);
if (exec($modifiedFilescommand, $output) === false) {
	echo "failed\n";
    exit(1);
}

echo "done\n";

$modifiedFiles = [];
$currentType = "";
foreach ($output as $line) {
    switch ($line) {
        case "filename:":
            $currentType = "filename";
            continue 2;
        case "modified:":
            $currentType = "modDateTime";
            continue 2;
        case "contributors:":
            $currentType = "contributors";
            continue 2;
        case "last commit hash:":
            $currentType = "commitHash";
            continue 2;
    }
    if ($currentType === "") {
        continue;
    }

    switch ($currentType) {
        case "filename":
            $currentFile = $line;
            break;
        case "modDateTime":
            if ($currentFile === "") {
                continue 2;
            }
            $modifiedFiles[$currentFile]["modified"] = $line;
            break;
        case "contributors":
            if ($currentFile === "") {
                continue 2;
            }
            $modifiedFiles[$currentFile]["contributors"][] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
            break;
        case "commitHash":
            $modifiedFiles["last commit hash"][] = $line;
            break;
    }
}

echo "Number of files modified: ";
if (count($modifiedFiles) === 1) {
    // there will always be 1 entry with the last commit hash
	echo "0\n";
    exit(1);
}

echo (count($modifiedFiles) - 1) . "\n";

$mergedModHistory = array_merge($modHistoryArray, $modifiedFiles);

$newModHistoryString = "<?php\n\n/* This is a generated file */\n\nreturn [\n";
foreach ($mergedModHistory as $fileName => $fileProps) {
    if ($fileName === "last commit hash") {
        $newModHistoryString .= "    \"last commit hash\" => \"" . implode("", $fileProps) . "\",\n";
        continue;
    }
    $newModHistoryString .= '    "' . $fileName . "\" => [\n";
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
}
$newModHistoryString .= "];\n";

echo "Writing modification history file... ";
if (file_put_contents($modHistoryFile, $newModHistoryString) === false) {
	echo "failed\n";
    exit(1);
}

echo "done\n";
