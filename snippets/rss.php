<?php

if (!isset($feedTitle)) : 
    throw new Exception('No $feedTitle supplied');
endif;

if (!isset($feedLink)) : 
    throw new Exception('No $feedLink supplied');
endif;

if (!isset($feedDescription)) : 
    throw new Exception('No $feedDescription supplied');
endif;

if (!isset($feedUrl)) : 
    throw new Exception('No $feedUrl supplied');
endif;

if (!isset($posts)) : 
    throw new Exception('No $posts supplied');
endif;

echo '<?xml version="1.0" encoding="utf-8"?>';
echo '<?xml-stylesheet href="/assets/css/pretty-feed-v3.xsl" type="text/xsl"?>' ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"  xmlns:atom="http://www.w3.org/2005/Atom" xmlns:georss="http://www.georss.org/georss" xmlns:gml="http://www.opengis.net/gml">
  <channel>
    <title><?=esc((string)$feedTitle, 'xml')?></title>
    <link><?=esc((string)$feedLink, 'xml')?></link>
    <description><?=esc((string)$feedDescription, 'xml')?></description>
    <language>en</language>
    <pubDate><?=date('r', time())?></pubDate>
    <lastBuildDate><?=date('r', time())?></lastBuildDate>
    <atom:link href="<?=esc((string)$feedUrl, 'xml')?>" rel="self" type="application/rss+xml"/>
<?php foreach($posts as $post): ?>
    <item>
      <title><?=esc((string)$post->title(), 'xml')?></title>
      <link><?=esc((string)$post->url(), 'xml')?></link>
      <description>
        <?php
        // the excerpt is HTML-entity-encoded text; decode before XML-escaping
        // so entities like &nbsp; (invalid in XML) become plain characters
        $itemDescription = html_entity_decode(
            $post->mainContent()->toBlocks()->excerpt(100),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        ?>
        <?=esc($itemDescription, 'xml')?>
      </description>
      <pubDate><?=date('r', $post->publishedDate()->toDate())?></pubDate>
      <guid isPermaLink="true"><?=esc((string)$post->url(), 'xml')?></guid>
    </item>
<?php endforeach ?>
  </channel>
</rss>