<?php
/**
 * PHP RFC voting data scraper
 *
 * @author Damien Walsh <me@damow.net>
 * @package vote-scraper
 */

namespace VoteScraper\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class DumpCommand extends Command
{
    const PHP_WIKI_URL = 'https://wiki.php.net/';

    protected function configure()
    {
        $this
            ->setName('dump')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to write the JSON voting data to')
            ->setDescription('Dump voting information as JSON.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get the PHP RFC List page
        $rfcListText = file_get_contents(self::PHP_WIKI_URL . '/rfc');

        // Extract the links
        $rfcListPageCrawler = new Crawler($rfcListText);
        $rfcLinksCrawler = $rfcListPageCrawler->filter('div.li a.wikilink1');
        $output->writeln('<info>Ok</info> - Downloaded ' . $rfcLinksCrawler->count() . ' RFCs');

        // Collect RFC data
        $rfcs = array();

        $rfcLinksCrawler->each(function (Crawler $rfcLink) use ($output, & $rfcs) {

            // Process this RFC
            $rfcName = $rfcLink->text();
            $rfcUrl = self::PHP_WIKI_URL . $rfcLink->attr('href');
            $rfcText = file_get_contents($rfcUrl);
            $rfc = array(
                'name' => $rfcName,
                'url' => $rfcUrl,
                'voters' => array()
            );
            $output->writeln('Scraping RFC: ' . $rfcName . '...');

            // Scrape the votes
            $rfcCrawler = new Crawler($rfcText);
            $rfcVotesCrawler = $rfcCrawler->filter('table.inline tbody tr');

            // Are there any vote rows? If not, skip
            if ($rfcVotesCrawler->count() === 0) {
                $output->writeln('    Skipped RFC - no voting recorded.');
                return;
            }

            $rfcVotesCrawler->each(function (Crawler $rfcLink) use ($output, & $rfc) {

                $rfcVoteCellCrawler = $rfcLink->filter('td');

                // Is this a vote row?
                if ($rfcVoteCellCrawler->count() === 0) {
                    return;
                }

                $voterName = trim($rfcVoteCellCrawler->eq(0)->text());

                // Verify that the voter name is valid - contains parens
                if (strpos($voterName, '(') === false) {
                    return;
                }

                // Determine the value of the vote
                // The right-hand column is always the "negative" vote
                // If it is checked, it will contain a "tick" image
                $wasNoVote = $rfcVoteCellCrawler->last()->children()->count() > 0;
                $rfc['voters'][$voterName] = !$wasNoVote;

                return;
            });

            $output->writeln('    Collected ' . count($rfc['voters']) . ' votes.');
            $rfcs[] = $rfc;
        });

        // Write the data
        $targetPath = $input->getArgument('path');
        file_put_contents($targetPath, json_encode($rfcs));
    }
}
