<?php

namespace App\Command;

use Exception;
use http\Client;
use Imagick;
use ImagickDrawException;
use ImagickException;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\HttpClient\HttpClient;
use XMLReader;

class ConvertFeedCommand extends Command
{

    protected static $defaultName = 'app:convert-feed';

    /**
     * @param $projectDir
     */
    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;

        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setDescription("Convert data from feed to img's with text");
    }

    /**
     * Main function that loads xml from url and generates saves images to public/processed as their {id}.png
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $output->writeln([
            '<info>Warming up engine</>',
            '<info>==========================</>',
            '',
        ]);
        //Usage of XMLReader and SimpleXMLElement makes that RAM usage is pretty much constant, only one item is read at a time
        $xml = new XMLReader();
        $uri = 'https://feedink-public.s3.eu-central-1.amazonaws.com/static/dev_sample_feed.xml';
        $xml->open($uri);

        //Loop to find record element
        while ($xml->read() && $xml->name != 'record') {
            ;
        }

        $output->writeln([
            '<info>Found data (it is also some success)</>',
            '',
        ]);

        $counter = 0;
        //Loop through all "record" elements
        while ($xml->name == 'record') {
            $counter++;
            $output->writeln([
                '<info>Processing record no.'.$counter.'</>',
            ]);
            $element = new SimpleXMLElement($xml->readOuterXML());

            $imagick = self::processImage($element);
            $imagick->writeImage($this->projectDir . '/public/processed/' . $element->id . '.png');
            $imagick->clear();

            $xml->next('record');
            unset($element);
        }

        $xml->close();
        $output->writeln([
            '',
            '<info>Finished, processed: '.$counter.' records</>',
        ]);
        return 0;
    }


    /**
     * Function that generates image from given element data.
     *
     * @param $element
     * @return Imagick
     * @throws ImagickDrawException
     * @throws ImagickException
     */
    public static function processImage($element): Imagick
    {

        $fontSize = 24;
        $size = 1000;


        //Tried file_get_contents, but image service returned 403 forbidden so used symfony client instead
        $client = HttpClient::create();
        $response = $client->request('GET', $element->image_link);

        $imagick = new \Imagick();
        $imagick->readImageBlob($response->getContent());

        //Scaling image to 1000x1000
        $imagick->scaleImage($size, $size, true);

        //Reading data about text
        $draw = new \ImagickDraw();
        $draw->setFont("Arial");
        $draw->setFontSize($fontSize);

        list($linesTop, $lineHeightTop) = self::simpleWrapper($imagick, $draw, $element->title);
        list($linesBottom, $lineHeightBottom) = self::simpleWrapper($imagick, $draw, $element->price);

        //Making bars objects
        $topBar = self::composeBar($size, $linesTop, $lineHeightTop);
        $bottomBar = self::composeBar($size, $linesBottom, $lineHeightBottom);


        //Gluing everything together
        $imagick->cropThumbnailImage($size, $size);
        $imagick->compositeImage($topBar, \Imagick::COMPOSITE_OVER, 0, 0);
        $imagick->compositeImage($bottomBar, \Imagick::COMPOSITE_OVER, 0, $size - 50 - count($linesBottom) * $lineHeightBottom);

        $imagick->setImageFormat("png");

        return $imagick;
    }

    /**
     * Function that checks if text isn't too long for image size and breaks it by spaces (yes if it is one really long string it will not wrap.)
     *
     * @param $imagick
     * @param $draw
     * @param $text
     * @param int $maxWidth
     * @return array
     */
    public static function simpleWrapper($imagick, $draw, $text, int $maxWidth = 500)
    {
        $text = trim($text);

        //Splitting by whitespaces (also tabs, double spaces, etc.)
        $words = preg_split('%\s%', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = array();
        $i = 0;
        $lineHeight = 0;

        while (count($words) > 0)
        {

            //Reading text data to decide where to split
            $metrics = $imagick->queryFontMetrics($draw, implode(' ', array_slice($words, 0, ++$i)));
            $lineHeight = max($metrics['textHeight'], $lineHeight);

            if ($metrics['textWidth'] > $maxWidth or count($words) < $i)
            {
                if ($i == 1)
                    $i++;

                $lines[] = implode(' ', array_slice($words, 0, --$i));
                $words = array_slice($words, $i);
                $i = 0;
            }
        }

        return array($lines, $lineHeight);
    }

    /**
     * Function that composes bars from given data
     *
     * @param $size
     * @param $lines
     * @param $lineHeight
     * @return Imagick
     * @throws ImagickDrawException
     * @throws ImagickException
     */
    public static function composeBar($size, $lines, $lineHeight): Imagick
    {
        $bar = new \Imagick();
        $bar->newImage($size, count($lines)*$lineHeight + 50, "gray");
        $draw = new \ImagickDraw();
        $draw->setFont("Arial");
        $draw->setFontSize($lineHeight);
        $draw->setFillColor("white");
        for($i = 0; $i < count($lines); $i++){
            $draw->annotation(50, 50 + $i*$lineHeight, $lines[$i]);
        }
        $bar->drawImage($draw);

        return $bar;
    }
}

