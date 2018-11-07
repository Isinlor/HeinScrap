<?php

use League\Csv\Writer;
use Symfony\Component\DomCrawler\Crawler;
use function Stringy\create as s;

require __DIR__ . "/vendor/autoload.php";

function outerHTML($e)
{
    $doc = new DOMDocument();
    $doc->appendChild($doc->importNode($e, true));
    return $doc->saveHTML();
}

function compactWhitespace($string)
{
    return preg_replace('/[\pZ\pC]+/u', ' ', $string);
}

function unicodeTrim($string)
{
    return preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $string);
}

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

function scrap($file)
{

    $html = file_get_contents($file);

    $pdfHandlesMatches = regexMatchAll($html, "/href=\"(.+?PrintRequest\?handle=(.*?div=\d*).*?)\"/");

    // map links to handles
    $pdfURLs = array_combine($pdfHandlesMatches[2], $pdfHandlesMatches[1]);

    $crawler = new Crawler($html);

    $data = [];
    foreach ($crawler->filter("div.section_type_article_b") as $element) {

        $elementHtml = outerHTML($element);
        $innerCrawler = new Crawler($elementHtml);

        $article = [];

        // extract HeinOnline handle
        $handle = regexMatch($elementHtml, "/\?handle=(.*?div=\d*)&/")[1];
        $article[] = $handle;

        // has pdf
        $article[] = $pdfURLs[$handle] ?? "";

//        // extract OpenURL
//        $openURL = "";
//        try {
//            $openURL = $innerCrawler
//                    ->filter(".Z3988")
//                    ->extract(["title"])[0] ?? "";
//
//            echo $openURL . "\n\n";
//        } catch (\Exception $e) {
//            // no op
//        }
//        $article[] = $openURL;

        // extract number of matching pages
        $matches = regexMatch($elementHtml, "/All Matching Text Pages \((\d+)\)/");
        $article[] = $matches[1] ?? null;

        // extract first matching snippet
        $searchSnippet = "";
        try {
            $searchSnippet = (string)s(strip_tags(
                $innerCrawler
                    ->filter(".searchvolume_results")
                    ->html()
            ))->regexReplace("^[\w\d\s]*Turn to page", "");
        } catch (\InvalidArgumentException $e) {
            // no op
        }
        $article[] = $searchSnippet;

        foreach ($innerCrawler->filter(".search_result_line") as $searchLine) {
            $searchLineText = compactWhitespace(unicodeTrim(strip_tags(
                outerHTML($searchLine)
            )));
            if (!empty($searchLineText)) {
                $article[] = $searchLineText;
            }
        }

        $data[] = $article;

    }

    return $data;
}

$data = [];
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-1000.html"));
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-2000.html"));
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-3000.html"));
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-4000.html"));
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-5000.html"));
$data = array_merge($data, scrap(__DIR__ . "/HeinOnline-6000.html"));

$csvWriter = Writer::createFromString('');

$csvWriter->insertAll($data);

file_put_contents(__DIR__ . "/data.csv", $csvWriter->getContent());