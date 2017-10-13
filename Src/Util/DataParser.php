<?php
/**
 * This package contains some code that reused by other repository(es) for private uses.
 * But on some certain conditions, it will also allowed to used as commercials project.
 * Some code & coding standard also used from other repositories as inspiration ideas.
 * And also uses 3rd-Party as to be used as result value without their permission but permit to be used.
 *
 * @license GPL-3.0  {@link https://www.gnu.org/licenses/gpl-3.0.en.html}
 * @copyright (c) 2017. Pentagonal Development
 * @author pentagonal <org@pentagonal.org>
 */

namespace Pentagonal\WhoIs\Util;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class DataParser
 * @package Pentagonal\WhoIs\Util
 */
class DataParser
{
    const REGISTERED    = true;
    const UNREGISTERED  = false;
    const UNKNOWN = 'UNKNOWN';
    const LIMIT   = 'LIMIT';

    /**
     * @param string $data
     *
     * @return string
     */
    public static function cleanIniComment(string $data) : string
    {
        $data = trim($data);
        if ($data == '') {
            return $data;
        }

        return preg_replace(
            '/^(?:\;|\#)[^\n]+\n?/m',
            '',
            $data
        );
    }

    /**
     * Clean Slashed Comment
     *
     * @param string $data
     *
     * @return string
     */
    public static function cleanSlashComment(string $data) : string
    {
        $data = trim($data);
        if ($data == '') {
            return $data;
        }

        return preg_replace(
            '/^(?:\/\/)[^\n]+\n?/sm',
            '',
            $data
        );
    }

    /**
     * Clean Multiple whitespace
     *
     * @param string $data
     * @param bool $allowEmptyNewLine allow one new line
     *
     * @return string
     */
    public static function cleanMultipleWhiteSpaceTrim(string $data, $allowEmptyNewLine = false) : string
    {
        $data = str_replace(
            ["\r\n", "\t",],
            ["\n", " "],
            $data
        );

        if (!$allowEmptyNewLine) {
            return trim(preg_replace(['/^[\s]+/m', '/(\n)[ ]+/'], ['', '$1'], $data));
        }

        $data = preg_replace(
            ['/(?!\n)([\s])+/m', '/(\n)[ ]+/', '/([\n][\n])[\n]+/m'],
            '$1',
            $data
        );

        return trim($data);
    }

    /**
     * @param string $data
     *
     * @return mixed|string
     */
    public static function cleanWhoIsResultComment(string $data)
    {
        $data = str_replace("\r", "", $data);
        $data = preg_replace('/^(\#|\%)[^\n]+\n?/m', '', $data);
        return trim($data);
    }

    /**
     * Get Whois Date Update
     *
     * @param string $data
     *
     * @return string|null
     */
    public static function getWhoIsLastUpdateDatabase(string $data)
    {
        $data = str_replace(["\r", "\t"], ["", " "], trim($data));
        preg_match(
            '/
                (?:\>\>\>?)?\s*
                (?P<information>Last\s*Update\s*(?:[a-z0-9\s]+)?
                  (?:\s+Whois\s*)?
                  (?:\s+Database)?
                )\s*
                \:\s*(?P<date_update>(?:[0-9]+[0-9TZ\-\:\s]+)?)
            /ix',
            $data,
            $match
        );

        if (empty($match['date_update'])) {
            return null;
        }
        $data =  "{$match['information']}: {$match['date_update']}";
        $data = preg_replace('/(\s)+/', '$1', trim($data));
        return trim($data);
    }

    /**
     * Get Whois Date Update
     *
     * @param string $data
     *
     * @return string|null
     */
    public static function getICANNReportUrl(string $data)
    {
        $data = str_replace(["\r", "\t"], ["", " "], trim($data));
        preg_match(
            '/
                URL\s+of(?:\s+the)?\s+ICANN[^\:]+\:\s*
                (?P<url_uri>(?:http\:\\/\/)[^\n]+)
            /ix',
            $data,
            $match
        );

        if (empty($match['date_update'])) {
            return null;
        }

        $data =  "{$match['information']}: {$match['date_update']}";
        $data = preg_replace('/(\s)+/', '$1', trim($data));
        return trim($data);
    }

    /**
     * @param string $data
     *
     * @return mixed|string
     */
    public static function cleanWhoIsResultInformationalData(string $data)
    {
        $data = preg_replace(
            '~
            (?:
                \>\>\>?   # information
                |Terms\s+of\s+Use\s*:\s+Users?\s+accessing  # terms
                |URL\s+of\s+the\s+ICANN\s+WHOIS # informational from icann 
            ).*
            ~isx',
            '',
            $data
        );

        return $data;
    }

    /**
     * @param string $data
     *
     * @return mixed|string
     */
    public static function cleanUnwantedWhoIsResult(string $data)
    {
        if (!trim($data) === '') {
            return '';
        }

        // clean the data
        $cleanData = static::cleanWhoIsResultComment($data);
        $cleanData = static::cleanWhoIsResultInformationalData($cleanData);
        $cleanData = static::cleanMultipleWhiteSpaceTrim($cleanData);
        if ($cleanData && ($dateUpdated = static::getWhoIsLastUpdateDatabase($data))) {
            $cleanData .= "\n{$dateUpdated}";
        }

        return $cleanData;
    }

    /**
     * Domain Parser Registered or Not Callback
     *
     * @param string $data
     *
     * @return bool|string string if unknown result or maybe empty result / limit exceeded or bool if registered or not
     * @uses DataParser::LIMIT
     * @uses DataParser::UNKNOWN
     */
    public static function hasRegisteredDomain(string $data)
    {
        // check if empty result
        if (($cleanData = static::cleanUnwantedWhoIsResult($data)) === '') {
            // if cleanData is empty & data is not empty check entries
            if ($data && preg_match('/No\s+entries(?:\s+found)?|Not(?:hing)?\s+found/i', $data)) {
                return static::UNREGISTERED;
            }

            return static::UNKNOWN;
        }

        // if invalid domain
        if (stripos($cleanData, 'Failure to locate a record in ') !== false) {
            return static::UNKNOWN;
        }

        // array check for detailed content only that below is not registered
        $matchUnRegistered = [
            'domain not found',
            'not found',
            'no data found',
            'no match',
            'No such domain',
            'this domain name has not been registered',
            'the queried object does not exist',
        ];

        // clean dot on both side
        $cleanData = trim($cleanData, '.');
        if (in_array(strtolower($cleanData), $matchUnRegistered)
            // for za domain eg: co.za
            || stripos($cleanData, 'Available') === 0 && strpos($cleanData, 'Domain:')
        ) {
            return static::UNREGISTERED;
        }

        // regex not match or not found on start tag
        if (preg_match(
            '/^(?:
                    No\s+match\s+for
                    | No\s+Match
                    | Not\s+found\s*\:?
                    | No\s*Data\s+Found
                    | Domain\s+not\s+found
                    | Invalid\s+query\s+or\s+domain
                    | The\s+queried\s+object\s+does\s+not\s+exist
                    | (?:Th(?:is|e))\s+domain(?:\s*name)?\s+has\s*not\s+been\s+register
                )/ix',
            $cleanData
        )
            || preg_match(
                '/Domain\s+Status\s*\:\s*(available|No\s+Object\s+Found)/im',
                $cleanData
            )
            // match for queried object
            || preg_match(
                '/^(?:.+)\s*(?:No\s+match|not\s+exist\s+[io]n\s+database(?:[\!]+)?)$/',
                $cleanData
            )
        ) {
            return static::UNREGISTERED;
        }
        // match domain with name and with status available extension for eg: .be
        if (preg_match('/Domain\s*(?:\_name)?\:(?:[^\n]+)/i', $cleanData)) {
            if (preg_match(
                '/
                    (?:Domain\s+)?Status\s*\:\s*(?:AVAILABLE|(?:No\s+Object|Not)\s+Found)
                    | query_status\s*:\s*220\s*Available
                /ix',
                $cleanData
            )) {
                return static::UNREGISTERED;
            }

            if (preg_match(
                '/(?:Domain\s+)?Status\s*\:\s*NOT\s*AVAILABLE/i',
                $cleanData
            )) {
                return static::REGISTERED;
            }
        }

        if (stripos($cleanData, 'Status: Not Registered') !== false
            && preg_match('/[\n]+Query\s*\:[^\n]+/', $cleanData)
        ) {
            return static::UNREGISTERED;
        }

        // Reserved Domain
        if (preg_match('/^\s*Reserved\s*By/i', $cleanData)
            // else check contact or status billing, tech or contact
            || preg_match(
                '/
                (
                    Registr(?:ar|y|nt)\s[^\:]+
                    | Whois\s+Server
                    | (?:Phone|Registrar|Contact|(?:admin|tech)-c|Organisations?)
                )\s*\:\s*([^\n]+)
                /ix',
                $cleanData,
                $matchData
            )
            && !empty($matchData[1])
            && (
               // match for name server
               preg_match(
                   '/(?:(?:Name\s+Servers?|n(?:ame)?servers?)\s*\:\s*)([^\n]+)/i',
                   $cleanData,
                   $matchServer
               )
               && !empty($matchServer[1])
               // match for billing
               || preg_match(
                   '/(?:Billing|Tech)\s+Email\s*:([^\n]+)/i',
                   $cleanData,
                   $matchDataResult
               ) && !empty($matchDataResult[1])
           )
        ) {
            return static::REGISTERED;
        }

        // check if on limit whois check
        if (static::hasContainLimitedResultData($data)) {
            return static::LIMIT;
        }

        return static::UNREGISTERED;
    }

    /**
     * Check if Whois Result is Limited
     *
     * @param string $data clean string data from whois result
     *
     * @return bool
     */
    public static function hasContainLimitedResultData(string $data) : bool
    {
        if (preg_match(
            '/passed\s+(?:the)?\s+daily\s+limit|temporarily\s+denied/i',
            $data
        )) {
            return true;
        }

        // check if on limit whois check
        if (($data = static::cleanUnwantedWhoIsResult($data)) !== ''
            && preg_match(
                '/
                (?:Resource|Whois)\s+Limit
                | exceeded\s(.+)?limit
                | limit\s+exceed
                |allow(?:ed|ing)?\s+quer(?:ies|y)\s+exceeded
            /ix',
                $data
            )) {
            return true;
        }

        return false;
    }

    /**
     * Parse Whois Server from whois result data
     *
     * @param string $data
     *
     * @return bool|string
     */
    public static function getWhoIsServerFromResultData(string $data)
    {
        if (trim($data) === '') {
            return false;
        }

        preg_match('/Whois\s*Server\s*:([^\n]+)/i', $data, $match);
        return !empty($match[1])
            ? trim($match[1])
            : false;
    }

    /**
     * @param RequestInterface $request
     * @param bool $useClone
     *
     * @return string
     */
    public static function convertRequestBodyToString(RequestInterface $request, $useClone = true) : string
    {
        return self::convertStreamToString($request->getBody(), $useClone);
    }

    /**
     * Convert ResponseInterface body to string
     *
     * @param ResponseInterface $response
     * @param bool $useClone
     *
     * @return string
     */
    public static function convertResponseBodyToString(ResponseInterface $response, $useClone = true) : string
    {
        return self::convertStreamToString($response->getBody(), $useClone);
    }

    /**
     * @param StreamInterface $stream
     * @param bool $useClone            true if resource clone
     *
     * @return string
     */
    public static function convertStreamToString(StreamInterface $stream, $useClone = true) : string
    {
        $data = '';
        $stream = $useClone ? clone $stream : $stream;
        while (!$stream->eof()) {
            $data .= $stream->read(4096);
        }

        $stream->close();

        return $data;
    }
}
