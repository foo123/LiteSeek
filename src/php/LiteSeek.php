<?php
/**
*  LiteSeek
*  A lite, fast and versatile fuzzy full-text search engine for PHP, JavaScript, Python
*
*  @version: 1.0.0
*  https://github.com/foo123/LiteSeek
*
**/
if (!class_exists('LiteSeek', false))
{
class LiteSeek
{
    const VERSION = "1.0.0";

    const DELIM = '#[\\s\\.\\?,;!:\\(\\)\\[\\]@\\#\\$%\\^&\\*\\-_\\+<>=\\/\\\\"\']#';
    const SPACE = '#\\s+#';
    const ASCII = '#^[ -~]+$#';

    protected $opts = array();

    public function __construct()
    {
        // some defaults
        $this->option('match-prefix', false);
        $this->option('similarity', 0.6);
        $this->option('n-gram', 2);
        $this->option('filter_word', null);
        $this->option('normalize_word', null);
        $this->option('read_index', function($id, $key, $locale) {return null;});
        $this->option('store_index', function($id, $index, $locale) {});
    }

    public function option($key, $val = null)
    {
        $nargs = func_num_args();
        if (1 == $nargs)
        {
            return isset($this->opts[$key]) ? $this->opts[$key] : null;
        }
        elseif (1 < $nargs)
        {
            $this->opts[$key] = $val;
        }
        return $this;
    }

    public function index($documentText, $documentId = null, $locale = null)
    {
        $filter_word = $this->option('filter_word');
        $normalize_word = $this->option('normalize_word');
        if (!is_callable($filter_word)) $filter_word = null;
        if (!is_callable($normalize_word)) $normalize_word = null;
        $s = (string)$documentText;
        $l = mb_strlen($s, 'UTF-8');
        $N = $this->option('n-gram');
        $documentIndex = array();
        $w = ''; $j = 0; $p = 0;
        for ($i=0; $i<$l; ++$i)
        {
            $c = mb_substr($s, $i, 1, 'UTF-8');
            // if delimiter bypass
            if (preg_match(LiteSeek::DELIM, $c))
            {
                if ('' !== $w)
                {
                    if (!$filter_word || call_user_func($filter_word, $w, $locale))
                    {
                        $n = mb_strlen($w, 'UTF-8');
                        $w = $normalize_word ? call_user_func($normalize_word, $w, $locale) : $this->normalize($w, $locale);
                        $ngram = $this->ngram($w, $N);
                        foreach (array_keys($ngram) as $k)
                        {
                            if (!isset($documentIndex[$k]))
                            {
                                $documentIndex[$k] = array();
                            }
                            //                           order, word, startpos, len
                            $documentIndex[$k][] = array($p,    $w,   $j,       $n);
                        }
                        $p += 1;
                    }
                }
                $w = '';
            }
            else
            {
                if ('' === $w) $j = $i;
                $w .= $c;
            }
        }
        if ('' !== $w)
        {
            if (!$filter_word || call_user_func($filter_word, $w, $locale))
            {
                $n = mb_strlen($w, 'UTF-8');
                $w = $normalize_word ? call_user_func($normalize_word, $w, $locale) : $this->normalize($w, $locale);
                $ngram = $this->ngram($w, $N);
                foreach (array_keys($ngram) as $k)
                {
                    if (!isset($documentIndex[$k]))
                    {
                        $documentIndex[$k] = array();
                    }
                    //                           order, word, startpos, len
                    $documentIndex[$k][] = array($p,    $w,   $j,       $n);
                }
                $p += 1;
            }
        }
        if ($documentId)
        {
            call_user_func($this->option('store_index'), $documentId, $documentIndex, $locale);
        }
        return $documentIndex;
    }

    public function find($documents, $query, $exact = false, $consecutive = false, $locale = null)
    {
        $index = is_string($documents) ? $this->index($documents, null) : null;
        $results = array();
        if (!empty($index) || (is_array($documents) && !empty($documents)))
        {
            $exact = !empty($exact);
            $consecutive = !empty($consecutive);

            $words = array_values(array_filter(
                preg_split(
                    LiteSeek::SPACE,
                    trim(preg_replace(
                        // strip delimiters ..
                        LiteSeek::DELIM,
                        ' ',
                        $query
                    ))
                ),
                'strlen'
            ));
            $terms = array();

            $filter_word = $this->option('filter_word');
            $normalize_word = $this->option('normalize_word');
            if (!is_callable($filter_word)) $filter_word = null;
            if (!is_callable($normalize_word)) $normalize_word = null;

            foreach ($words as $word)
            {
                if ($filter_word && !call_user_func($filter_word, $word, $locale))
                {
                    continue;
                }
                $terms[] = $normalize_word ? call_user_func($normalize_word, $word, $locale) : $this->normalize($word, $locale);
            }

            if (empty($terms)) return $results;

            $t = -1000000;

            if (!empty($index))
            {
                $res = $this->match(null, $terms, $exact, $consecutive, $locale, $index);
                if ($t < $res['score'])
                {
                    $results[] = array(
                        'document'  => $documents,
                        'query'     => $query,
                        'score'     => $res['score'],
                        'marks'     => $res['marks'],
                    );
                }
            }
            else
            {
                foreach (array_values($documents) as $d)
                {
                    $res = $this->match($d, $terms, $exact, $consecutive, $locale, null);
                    if ($t < $res['score'])
                    {
                        $results[] = array(
                            'document'  => $d,
                            'query'     => $query,
                            'score'     => $res['score'],
                            'marks'     => $res['marks'],
                        );
                    }
                }
            }
            usort($results, function($a, $b) {
                return $b['score'] - $a['score'];
            });
        }
        return $results;
    }

    protected function match($document, $terms, $exact = false, $consecutive = false, $locale = null, $document_index = null)
    {
        $seeker = $this;
        $threshold = $seeker->option('similarity');
        $N = $seeker->option('n-gram');
        $nterms = count($terms);
        $index = array(); // cache

        $get_index = function($key) use (&$seeker, $document, $locale, &$document_index, &$index) {
            if (isset($document_index))
            {
                return isset($document_index[$key]) ? $document_index[$key] : null;
            }
            elseif ($index && isset($index[$key]))
            {
                return $index[$key];
            }
            else
            {
                $read_index = call_user_func($seeker->option('read_index'), $document, $key, $locale);
                if (is_array($read_index) && !empty($read_index) && !isset($read_index[0]))
                {
                    // whole index returned, store it
                    $index = null;
                    $document_index = $read_index;
                    return isset($document_index[$key]) ? $document_index[$key] : null;
                }
                else
                {
                    // index for key returned, cache it
                    if (empty($read_index)) $read_index = array();
                    $index[$key] = $read_index;
                    return $read_index;
                }
            }
        };
        $merge = function($a, $b, $pos) {
            $intersect = 0;
            if (empty($b))
            {
                $ab = $a;
            }
            else
            {
                $ab = array();
                $n = count($a);
                $m = count($b);
                $i = 0;
                $j = 0;
                //while ($i < $n && $a[$i][0] < $pos) ++$i;
                while ($j < $m && $b[$j][0] < $pos) ++$j;
                while ($i < $n && $j < $m)
                {
                    if ($a[$i][0] < $b[$j][0])
                    {
                        $ab[] = $a[$i];
                        ++$i;
                    }
                    elseif ($a[$i][0] > $b[$j][0])
                    {
                        $ab[] = $b[$j];
                        ++$j;
                    }
                    else
                    {
                        $ab[] = $a[$i];
                        ++$i; ++$j;
                        $intersect = 1;
                    }
                }
                while ($i < $n)
                {
                    $ab[] = $a[$i];
                    ++$i;
                }
                while ($j < $m)
                {
                    $ab[] = $b[$j];
                    ++$j;
                }
            }
            return array($ab, $intersect);
        };
        $match = function($i, $j, $j0) use (&$match, &$get_index, &$merge, &$seeker,
                                            &$terms, $nterms, $N, $threshold, $exact, $consecutive) {
            if ($i >= $nterms) return null; // end of match
            $term = $terms[$i];
            $ngram = $seeker->ngram($term, $N);
            $l = mb_strlen($term, 'UTF-8');
            $k = round((1-$threshold)*$l);
            $index = array();
            $intersections = 0;
            foreach (array_keys($ngram) as $key)
            {
                list($index, $intersect) = $merge($index, $get_index($key), $j);
                $intersections += $intersect;
            }
            if (empty($index) || ($l-$N-$intersections > $k)) return false; // no match
            $matcher = new LiteSeekAutomaton($term, $k);
            $best = null;
            $max_score = -200000;
            foreach ($index as $entry)
            {
                $k = $entry[0]; // order in doc of next word
                if ($consecutive && (0 < $i) && ($k > $j0+$i)) break; // consecutive and no consecutive match, stop
                $word = $entry[1]; // word at this point

                // try to match this term
                $similarity = ($word === $term) ? 1 : ($exact ? 0 : $matcher->match($word));
                if ($threshold > $similarity) continue; // not good match

                // try to match rest terms
                $res = $match($i+1, $k+1, 0 === $i ? $k : $j0);
                if (false !== $res)
                {
                    // matched
                    $score = $j - $k - (1 - $similarity)*10;
                    $marks = array(array($entry[2], $entry[3])); // marks of this match in document
                    if ($res)
                    {
                        $score += $res['score'];
                        $marks = array_merge($marks, $res['marks']);
                    }
                    if ($score > $max_score)
                    {
                        // current best match
                        $max_score = $score;
                        $best = array(
                            'score' => $score,
                            'marks' => $marks,
                        );
                    }
                }
            }
            return (0 < $i) && empty($best) ? false : $best;
        };
        $res = $match(0, -1, -1);
        return $res ? $res : array('score' => -2000000, 'marks' => array());
    }

    protected function ngram($s, $n)
    {
        $c = max(1, mb_strlen($s, 'UTF-8') - $n + 1);
        $ngram = array();
        for ($i=0; $i<$c; ++$i)
        {
            $k = mb_substr($s, $i, $n, 'UTF-8');
            if (!isset($ngram[$k])) $ngram[$k] = 0;//array();
            $ngram[$k] += 1;//[] = $i;
        }
        return $ngram;
    }

    public function normalize($string, $locale = null)
    {
        return $this->normalizeAccents(mb_strtolower($string, 'UTF-8'), $locale);
    }

    public function normalizeAccents($string, $locale = null)
    {
        // normalize some common utf8 character accents
        // Adapted from WordPress
        // https://github.com/WordPress/WordPress/blob/master/wp-includes/formatting.php
        if (preg_match(LiteSeek::ASCII, $string)) return $string;

        $map_ = array(
            // Decompositions for Latin-1 Supplement
            'ª' => 'a',
            'º' => 'o',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ð' => 'D',
            'Ñ' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'ß' => 's',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'd',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
            'Ø' => 'O',
            // Decompositions for Latin Extended-A
            'Ā' => 'A',
            'ā' => 'a',
            'Ă' => 'A',
            'ă' => 'a',
            'Ą' => 'A',
            'ą' => 'a',
            'Ć' => 'C',
            'ć' => 'c',
            'Ĉ' => 'C',
            'ĉ' => 'c',
            'Ċ' => 'C',
            'ċ' => 'c',
            'Č' => 'C',
            'č' => 'c',
            'Ď' => 'D',
            'ď' => 'd',
            'Đ' => 'D',
            'đ' => 'd',
            'Ē' => 'E',
            'ē' => 'e',
            'Ĕ' => 'E',
            'ĕ' => 'e',
            'Ė' => 'E',
            'ė' => 'e',
            'Ę' => 'E',
            'ę' => 'e',
            'Ě' => 'E',
            'ě' => 'e',
            'Ĝ' => 'G',
            'ĝ' => 'g',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ġ' => 'G',
            'ġ' => 'g',
            'Ģ' => 'G',
            'ģ' => 'g',
            'Ĥ' => 'H',
            'ĥ' => 'h',
            'Ħ' => 'H',
            'ħ' => 'h',
            'Ĩ' => 'I',
            'ĩ' => 'i',
            'Ī' => 'I',
            'ī' => 'i',
            'Ĭ' => 'I',
            'ĭ' => 'i',
            'Į' => 'I',
            'į' => 'i',
            'İ' => 'I',
            'ı' => 'i',
            'Ĵ' => 'J',
            'ĵ' => 'j',
            'Ķ' => 'K',
            'ķ' => 'k',
            'ĸ' => 'k',
            'Ĺ' => 'L',
            'ĺ' => 'l',
            'Ļ' => 'L',
            'ļ' => 'l',
            'Ľ' => 'L',
            'ľ' => 'l',
            'Ŀ' => 'L',
            'ŀ' => 'l',
            'Ł' => 'L',
            'ł' => 'l',
            'Ń' => 'N',
            'ń' => 'n',
            'Ņ' => 'N',
            'ņ' => 'n',
            'Ň' => 'N',
            'ň' => 'n',
            'ŉ' => 'n',
            'Ŋ' => 'N',
            'ŋ' => 'n',
            'Ō' => 'O',
            'ō' => 'o',
            'Ŏ' => 'O',
            'ŏ' => 'o',
            'Ő' => 'O',
            'ő' => 'o',
            'Ŕ' => 'R',
            'ŕ' => 'r',
            'Ŗ' => 'R',
            'ŗ' => 'r',
            'Ř' => 'R',
            'ř' => 'r',
            'Ś' => 'S',
            'ś' => 's',
            'Ŝ' => 'S',
            'ŝ' => 's',
            'Ş' => 'S',
            'ş' => 's',
            'Š' => 'S',
            'š' => 's',
            'Ţ' => 'T',
            'ţ' => 't',
            'Ť' => 'T',
            'ť' => 't',
            'Ŧ' => 'T',
            'ŧ' => 't',
            'Ũ' => 'U',
            'ũ' => 'u',
            'Ū' => 'U',
            'ū' => 'u',
            'Ŭ' => 'U',
            'ŭ' => 'u',
            'Ů' => 'U',
            'ů' => 'u',
            'Ű' => 'U',
            'ű' => 'u',
            'Ų' => 'U',
            'ų' => 'u',
            'Ŵ' => 'W',
            'ŵ' => 'w',
            'Ŷ' => 'Y',
            'ŷ' => 'y',
            'Ÿ' => 'Y',
            'Ź' => 'Z',
            'ź' => 'z',
            'Ż' => 'Z',
            'ż' => 'z',
            'Ž' => 'Z',
            'ž' => 'z',
            'ſ' => 's',
            // Decompositions for Latin Extended-B
            'Ș' => 'S',
            'ș' => 's',
            'Ț' => 'T',
            'ț' => 't',
            // Vowels with diacritic (Vietnamese)
            // unmarked
            'Ơ' => 'O',
            'ơ' => 'o',
            'Ư' => 'U',
            'ư' => 'u',
            // grave accent
            'Ầ' => 'A',
            'ầ' => 'a',
            'Ằ' => 'A',
            'ằ' => 'a',
            'Ề' => 'E',
            'ề' => 'e',
            'Ồ' => 'O',
            'ồ' => 'o',
            'Ờ' => 'O',
            'ờ' => 'o',
            'Ừ' => 'U',
            'ừ' => 'u',
            'Ỳ' => 'Y',
            'ỳ' => 'y',
            // hook
            'Ả' => 'A',
            'ả' => 'a',
            'Ẩ' => 'A',
            'ẩ' => 'a',
            'Ẳ' => 'A',
            'ẳ' => 'a',
            'Ẻ' => 'E',
            'ẻ' => 'e',
            'Ể' => 'E',
            'ể' => 'e',
            'Ỉ' => 'I',
            'ỉ' => 'i',
            'Ỏ' => 'O',
            'ỏ' => 'o',
            'Ổ' => 'O',
            'ổ' => 'o',
            'Ở' => 'O',
            'ở' => 'o',
            'Ủ' => 'U',
            'ủ' => 'u',
            'Ử' => 'U',
            'ử' => 'u',
            'Ỷ' => 'Y',
            'ỷ' => 'y',
            // tilde
            'Ẫ' => 'A',
            'ẫ' => 'a',
            'Ẵ' => 'A',
            'ẵ' => 'a',
            'Ẽ' => 'E',
            'ẽ' => 'e',
            'Ễ' => 'E',
            'ễ' => 'e',
            'Ỗ' => 'O',
            'ỗ' => 'o',
            'Ỡ' => 'O',
            'ỡ' => 'o',
            'Ữ' => 'U',
            'ữ' => 'u',
            'Ỹ' => 'Y',
            'ỹ' => 'y',
            // acute accent
            'Ấ' => 'A',
            'ấ' => 'a',
            'Ắ' => 'A',
            'ắ' => 'a',
            'Ế' => 'E',
            'ế' => 'e',
            'Ố' => 'O',
            'ố' => 'o',
            'Ớ' => 'O',
            'ớ' => 'o',
            'Ứ' => 'U',
            'ứ' => 'u',
            // dot below
            'Ạ' => 'A',
            'ạ' => 'a',
            'Ậ' => 'A',
            'ậ' => 'a',
            'Ặ' => 'A',
            'ặ' => 'a',
            'Ẹ' => 'E',
            'ẹ' => 'e',
            'Ệ' => 'E',
            'ệ' => 'e',
            'Ị' => 'I',
            'ị' => 'i',
            'Ọ' => 'O',
            'ọ' => 'o',
            'Ộ' => 'O',
            'ộ' => 'o',
            'Ợ' => 'O',
            'ợ' => 'o',
            'Ụ' => 'U',
            'ụ' => 'u',
            'Ự' => 'U',
            'ự' => 'u',
            'Ỵ' => 'Y',
            'ỵ' => 'y',
            // Vowels with diacritic (Chinese, Hanyu Pinyin)
            'ɑ' => 'a',
            // macron
            'Ǖ' => 'U',
            'ǖ' => 'u',
            // acute accent
            'Ǘ' => 'U',
            'ǘ' => 'u',
            // caron
            'Ǎ' => 'A',
            'ǎ' => 'a',
            'Ǐ' => 'I',
            'ǐ' => 'i',
            'Ǒ' => 'O',
            'ǒ' => 'o',
            'Ǔ' => 'U',
            'ǔ' => 'u',
            'Ǚ' => 'U',
            'ǚ' => 'u',
            // grave accent
            'Ǜ' => 'U',
            'ǜ' => 'u',
            // modern greek accents
            'Ά' => 'Α',
            'ά' => 'α',
            'Έ' => 'Ε',
            'έ' => 'ε',
            'Ή' => 'Η',
            'ή' => 'η',
            'Ί' => 'Ι',
            'ί' => 'ι',
            'Ϊ' => 'Ι',
            'ϊ' => 'ι',
            'ΐ' => 'ι',
            'Ό' => 'Ο',
            'ό' => 'ο',
            'Ύ' => 'Υ',
            'ύ' => 'υ',
            'Ϋ' => 'Υ',
            'ϋ' => 'υ',
            'ΰ' => 'υ',
            'Ώ' => 'Ω',
            'ώ' => 'ω',
            'ς' => 'σ',
        );

        // Used for locale-specific rules
        if ('ca' === $locale)
        {
            $map_['l·l'] = 'll';
        }

        return strtr($string, $map_);
    }
}

class LiteSeekAutomaton
{
    public $k = 1;
    public $w = '';
    public $n = 0;

    public function __construct($word, $maxk = 1)
    {
        $this->w = (string)$word;
        $this->n = mb_strlen($this->w, 'UTF-8');
        $this->k = min(max((int)$maxk, 0), $this->n);
    }

    public function initial()
    {
        // only diagonals up to k max errors
        return array(
            range(0, $this->k, 1),
            range(0, $this->k, 1),
            array(),
            array(),
            ''
        );
    }

    public function transition($s, $c)
    {
        // damerau-levenshtein algorithm step-by-step
        $k = $this->k;
        $w = $this->w;
        $n = $this->n;
        $index = $s[0];
        $value = $s[1];
        $new_index = array();
        $new_value = array();
        $m = count($index);
        $prev_i = -1;
        $prev_v = 0;
        $next_i = -1;
        $index_2 = $s[2];
        $value_2 = $s[3];
        $cp = $s[4];
        $m2 = count($index_2);
        $j2 = 0;
        if ((0 < $m) && (0 === $index[0]) && ($value[0] < $k))
        {
            $i = 0;
            $v = $value[0] + 1;
            $prev_i = $i;
            $prev_v = $v;
            $new_index[] = $i;
            $new_value[] = $v;
        }
        foreach ($index as $j => $i)
        {
            if ($i >= $n) break;
            $d = mb_substr($w, $i, 1, 'UTF-8') === $c ? 0 : 1;
            $v = $value[$j] + $d; // L[i,ii] = L[i-1,ii-1] + d
            $next_i = $j+1 < $m ? $index[$j+1] : -1;
            ++$i;
            if ($i-1 === $prev_i)
            {
                $v = min($v, $prev_v + 1); // L[i,ii] = min(L[i,ii], L[i-1,ii] + 1)
            }
            if ($i === $next_i)
            {
                $v = min($v, $value[$j+1] + 1); // L[i,ii] = min(L[i,ii], L[i,ii-1] + 1)
            }
            if (($cp === mb_substr($w, $i-1, 1, 'UTF-8')) && ($c === mb_substr($w, $i-2, 1, 'UTF-8')))
            {
                while (($j2 < $m2) && ($index_2[$j2] < $i-2)) ++$j2;
                if (($j2 < $m2) && ($i-2 === $index_2[$j2]))
                {
                    $v = min($v, $value_2[$j2] + $d); // L[i,ii] = min(L[i,ii], L[i-2,ii-2] + d)
                    ++$j2;
                }
            }
            if ($v <= $k)
            {
                $prev_i = $i;
                $prev_v = $v;
                $new_index[] = $i;
                $new_value[] = $v;
            }
        }
        return array(
            $new_index,
            $new_value,
            $index,
            $value,
            $c
        );
    }

    public function terminal($s)
    {
        $index = $s[0];
        $n = count($index);
        return (0 < $n) && ($index[$n-1] === $this->n);
    }

    public function match($word, $state = null)
    {
        if (null === $state) $state = $this->initial();
        for ($i=0,$n=mb_strlen($word, 'UTF-8'); $i<$n; ++$i)
        {
            $char = mb_substr($word, $i, 1, 'UTF-8');
            $state = $this->transition($state, $char);
            if (empty($state[0])) return 0; // no match
        }
        return $this->terminal($state) ? (1 - $state[1][count($state[1])-1]/$this->n) : 0;
    }
}
}
