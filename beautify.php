<?php

use League\Csv\Reader;
use League\Csv\Writer;
use function Stringy\create as s;

require __DIR__ . "/vendor/autoload.php";

//load the CSV document from a file path
$csv = Reader::createFromPath(__DIR__ . "/data.csv", 'r');

function regexMatch($string, $pattern)
{
    $matches = [];
    preg_match($pattern, $string, $matches);
    return $matches;
}

function regexMatchAll($string, $pattern)
{
    $matches = [];
    preg_match_all($pattern, $string, $matches);
    return $matches;
}

function regexCheck($string, $pattern)
{
    return !empty(regexMatch($string, $pattern));
}

$isTitle = function ($string) {
    return s($string)->contains("[article]");
};

$beautifyTitle = function ($string) {
    return (string)s($string)
        ->regexReplace("\[article\]", "")
        ->regexReplace("\*new\*", "")
        ->regexReplace("&amp;", "&");
};

$isJournal = function ($string) {

    return s($string)->containsAny([
        " Vol.",
        " pp.",
    ]);

};

$beautifyJournal = function ($string) {

    $journal = [];
    $journal["name"] = (string)s($string)
        ->regexReplace(", Vol. .+", "")
        ->regexReplace("&amp;", "&");
    $journal["volume"] = regexMatch($string, "/Vol. (\d+)/")[1] ?? null;
    $journal["issue"] = regexMatch($string, "/Issue (\d+)/")[1] ?? null;

    $pages = regexMatch($string, "/pp. (\d+)-(\d+)/");
    $journal["from page"] = $pages[1] ?? null;
    $journal["to page"] = $pages[2] ?? null;

    return $journal;

};

$isAuthor = function ($string, $index) use ($isTitle, $isJournal) {

    $hasCitationInfo = s($string)->containsAll([
        "Cited",
        "times",
    ]);

    if ($hasCitationInfo) {
        return true;
    }

    // matching author is based on heuristics

    // third or fourth columns have authors
    if ($index !== 2 && $index !== 3) {
        return false;
    }

    // authors first name and last name are normally separated by comma
    if (!regexCheck($string, "/,/")) {
        return false;
    }

    // author names do not contain numbers
    if (regexCheck($string, "/\d/")) {
        return false;
    }

    // author names do not contain ( )
    if (regexCheck($string, "/\(/") || regexCheck($string, "/\)/")) {
        return false;
    }

    // author names should not match journal
    if ($isJournal($string)) {
        return false;
    }

    // author names should not match title
    if ($isTitle($string)) {
        return false;
    }

    return true;

};

$beautifyAuthor = function ($string) {

    $authors = [];

    $authorStrings = explode(";", $string);
    foreach ($authorStrings as $authorString) {

        if (empty($authorString)) {
            continue;
        }

        $author = [];

        $authorData = regexMatch($authorString, "/(.+) \(Cited (\d+) times\)/");

        if (empty($authorData)) {
            // some authors have no citation data
            $authorData[1] = $authorString;
        }

        $name = $authorData[1] ?? '';
        $citations = $authorData[2] ?? null;

        $name = (string)s($name)->trim(","); // some authors have spurious comma
        $names = explode(",", $name);

        $author["first name"] = (string)s($names[1] ?? '')->trim();
        $author["last name"] = (string)s($names[0] ?? '')->trim();
        $author["citations"] = $citations;

        if (isset($names[2])) { // sanity check
            echo "Unusual name: " . $string;
            exit;
        }

        //sanity check
        if (empty($author["first name"]) && empty($author["last name"])) {
            echo "Something wrong? " . $authorString;
            exit;
        }

        $authors[] = $author;

    }

    return $authors;
};

$findAndBeautify = function (array $record, callable $check, callable $beautify) {

    $found = false;
    $savedItem = '';

    foreach ($record as $index => $item) {
        if ($check($item, $index)) {

            if ($found) { // sanity check
                /** @noinspection ForgottenDebugOutputInspection */
                var_dump([
                    'savedItem' => $savedItem,
                    'item' => $item,
                ]);
            }

            $found = true;
            $savedItem = $item;

        }
    }

    return $beautify($savedItem);

};

$extractYears = function ($source) {
    $years = [];
    $yearsStrings = regexMatchAll($source, "/\([^\)]*\d\d\d\d\)/")[0] ?? [];

    foreach ($yearsStrings as $yearsString) {
        foreach (regexMatchAll($yearsString, "/\d\d\d\d/")[0] as $specificYear) {
            $years[] = $specificYear;
        }
    }

    $years = array_unique($years);
    sort($years);

    if (empty($years)) {
        return [];
    }

    if (count($years) < 2) {
        return ['start' => $years[0], 'end' => $years[0]];
    }

    return ['start' => array_shift($years), 'end' => array_pop($years)];
};

$empty = function ($string) {
    return $string;
};

$data = [];
$maxAuthors = 0;
foreach ($csv->getRecords() as $record) {

    $article = [];

    $article["handle"] = array_shift($record);
    $article["pdf"] = array_shift($record);
    $article["#matches"] = array_shift($record);
    $article["snippet"] = array_shift($record);

    $source = implode(" :: ", $record);

    $article["years"] = $extractYears($source);
    $article["title"] = $findAndBeautify($record, $isTitle, $beautifyTitle);
    $article["journal"] = $findAndBeautify($record, $isJournal, $beautifyJournal);
    $article["authors"] = $findAndBeautify($record, $isAuthor, $beautifyAuthor);
    $article["source"] = $source;

    $data[] = $article;

    $maxAuthors = max($maxAuthors, count($article['authors']));

}

// convert to 2D structure
$headers = [];
$beautifiedData = [];
foreach ($data as $datum) {

    $beautifiedDatum = [];

    //$beautifiedDatum["Source"] = $datum["source"];
    $beautifiedDatum["HeinOnline Handle"] = $datum["handle"];
    $beautifiedDatum["Link to PDF"] = $datum["pdf"];
    $beautifiedDatum["Number of matches"] = $datum["#matches"];
    $beautifiedDatum["Snippet"] = $datum["snippet"];

    $beautifiedDatum["Year (from)"] = $datum["years"]["start"] ?? null;
    $beautifiedDatum["Year (to)"] = $datum["years"]["end"] ?? null;
    $beautifiedDatum["Title"] = $datum["title"];
    foreach ($datum["journal"] as $itemName => $item) {
        $beautifiedDatum["Journal " . $itemName] = $item;
    }

    foreach ($datum["authors"] as $authorNumber => $author) {
        foreach ($author as $itemName => $item) {
            $beautifiedDatum["Author " . ($authorNumber + 1) . " " . $itemName] = $item;
        }
    }

    $keys = array_keys($beautifiedDatum);
    if (count($keys) > count($headers)) {
        $headers = $keys;
    }

    $beautifiedData[] = $beautifiedDatum;

}

$csvWriter = Writer::createFromString('');

$csvWriter->insertOne($headers);
$csvWriter->insertAll($beautifiedData);

file_put_contents(__DIR__ . "/beautifiedData.csv", $csvWriter->getContent());