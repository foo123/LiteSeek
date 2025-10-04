/**
*  LiteSeek
*  A lite, fast and versatile fuzzy full-text search engine for PHP, JavaScript, Python
*
*  @version: 1.0.0
*  https://github.com/foo123/LiteSeek
*
**/
!function(root, name, factory) {
"use strict";
if (('object' === typeof module) && module.exports) /* CommonJS */
    (module.$deps = module.$deps||{}) && (module.exports = module.$deps[name] = factory.call(root));
else if (('function' === typeof define) && define.amd && ('function' === typeof require) && ('function' === typeof require.specified) && require.specified(name) /*&& !require.defined(name)*/) /* AMD */
    define(name, ['module'], function(module) {factory.moduleUri = module.uri; return factory.call(root);});
else if (!(name in root)) /* Browser/WebWorker/.. */
    (root[name] = factory.call(root)||1) && ('function' === typeof(define)) && define.amd && define(function() {return root[name];});
}(  /* current root */          'undefined' !== typeof self ? self : this,
    /* module name */           "LiteSeek",
    /* module factory */        function ModuleFactory__LiteSeek(undef) {
"use strict";

function NOP() {}

function LiteSeek()
{
    // some defaults
    var self = this;
    self.opts = {};
    self.option('match-prefix', false);
    self.option('similarity', 0.6);
    self.option('n-gram', 2);
    self.option('filter_word', null);
    self.option('normalize_word', null);
    self.option('read_index', NOP);
    self.option('store_index', NOP);
}
LiteSeek.VERSION = "1.0.0";

LiteSeek.DELIM    = /[\s\.\?,;!:\(\)\[\]@#\$%\^&\*\-_\+<>=\/\\"']/;
LiteSeek.DELIM_g  = /[\s\.\?,;!:\(\)\[\]@#\$%\^&\*\-_\+<>=\/\\"']/g;
LiteSeek.SPACE    = /\s+/g;
LiteSeek.ASCII    = /^[ -~]+$/;

LiteSeek.prototype = {
    constructor: LiteSeek,

    opts: null,

    option: function(key, val) {
        var self = this, nargs = arguments.length;
        if (1 == nargs)
        {
            return isset(self.opts, key) ? self.opts[key] : undef;
        }
        else if (1 < nargs)
        {
            self.opts[key] = val;
        }
        return self;
    },

    index: async function(documentText, documentId, locale) {
        var self = this,
            filter_word = self.option('filter_word'),
            normalize_word = self.option('normalize_word'),
            s = String(documentText), l = s.length, ngram,
            N = self.option('n-gram'),
            documentIndex = {}, w = '', j = 0, p = 0, k, i, c, n;
        if (!is_callable(filter_word)) filter_word = null;
        if (!is_callable(normalize_word)) normalize_word = null;
        for (i=0; i<l; ++i)
        {
            c = s.charAt(i);
            // if delimiter bypass
            if (LiteSeek.DELIM.test(c))
            {
                if ('' !== w)
                {
                    if (!filter_word || (await filter_word(w, locale)))
                    {
                        n = w.length;
                        w = normalize_word ? (await normalize_word(w, locale)) : (self.normalize(w, locale));
                        ngram = self._ngram(w, N);
                        for (k in ngram)
                        {
                            if (!isset(ngram, k)) continue;
                            if (!isset(documentIndex, k))
                            {
                                documentIndex[k] = [];
                            }
                            //                     order, word, startpos, len
                            documentIndex[k].push([p,     w,    j,        n]);
                        }
                        p += 1;
                    }
                }
                w = '';
            }
            else
            {
                if ('' === w) j = i;
                w += c;
            }
        }
        if ('' !== w)
        {
            if (!filter_word || (await filter_word(w, locale)))
            {
                n = w.length;
                w = normalize_word ? (await normalize_word(w, locale)) : (self.normalize(w, locale));
                ngram = self._ngram(w, N);
                for (k in ngram)
                {
                    if (!isset(ngram, k)) continue;
                    if (!isset(documentIndex, k))
                    {
                        documentIndex[k] = [];
                    }
                    //                     order, word, startpos, len
                    documentIndex[k].push([p,     w,    j,        n]);
                }
                p += 1;
            }
        }
        if (documentId)
        {
            await self.option('store_index')(documentId, documentIndex, locale);
        }
        return documentIndex;
    },

    find: async function(documents, query, exact, consecutive, locale) {
        var self = this,
            index = is_string(documents) ? (await self.index(documents, null)) : null,
            results = [], q, consecutive, words, word, terms,
            filter_word, normalize_word, t, res, i, n;
        if (index || (is_array(documents) && documents.length))
        {
            exact = !!exact;
            consecutive = !!consecutive;

            words = (
                // strip delimiters ..
                String(query).replace(
                    LiteSeek.DELIM_g,
                    ' '
                ).trim()
            )
            .split(LiteSeek.SPACE)
            .filter(function(s) {
                return 0 < s.length;
            });
            terms = [];

            filter_word = self.option('filter_word');
            normalize_word = self.option('normalize_word');
            if (!is_callable(filter_word)) filter_word = null;
            if (!is_callable(normalize_word)) normalize_word = null;

            for (i=0,n=words.length; i<n; ++i)
            {
                word = words[i];
                if (filter_word && !(await filter_word(word, locale)))
                {
                    continue;
                }
                terms.push(normalize_word ? (await normalize_word(word, locale)) : self.normalize(word, locale));
            }

            if (!terms.length) return results;

            t = -1000000;

            if (index)
            {
                res = await self._match(null, terms, exact, consecutive, locale, index);
                if (t < res.score)
                {
                    results.push({
                        document  : documents,
                        query     : query,
                        score     : res.score,
                        marks     : res.marks
                    });
                }
            }
            else
            {
                for (i=0,n=documents.length; i<n; ++i)
                {
                    res = await self._match(documents[i], terms, exact, consecutive, locale, null);
                    if (t < res.score)
                    {
                        results.push({
                            document  : documents[i],
                            query     : query,
                            score     : res.score,
                            marks     : res.marks
                        });
                    }
                }
            }
            results.sort(function(a, b) {
                return b.score - a.score;
            });
        }
        return results;
    },

    _match: async function(document, terms, exact, consecutive, locale, document_index) {
        var seeker = this,
            threshold = seeker.option('similarity'),
            nterms = terms.length,
            N = seeker.option('n-gram'),
            index = {} /* cache */,
            get_index, merge, match, res;

        get_index = async function(key) {
            if (document_index)
            {
                return isset(document_index, key) ? document_index[key] : null;
            }
            else if (index && isset(index, key))
            {
                return index[key];
            }
            else
            {
                var read_index = await seeker.option('read_index')(document, key, locale);
                if (read_index && is_obj(read_index))
                {
                    // whole index returned, store it
                    index = null;
                    document_index = read_index;
                    return isset(document_index, key) ? document_index[key] : null;
                }
                else
                {
                    // index for key returned, cache it
                    index[key] = read_index || null;
                    return read_index;
                }
            }
        };
        merge = function(a, b, pos) {
            var ab, n, m, i, j, k, intersect = 0;
            if (!b || !b.length)
            {
                ab = a;
            }
            else
            {
                ab = [];
                n = a.length;
                m = b.length;
                i = 0;
                j = 0;
                //while (i < n && a[i][0] < pos) ++i;
                while (j < m && b[j][0] < pos) ++j;
                while (i < n && j < m)
                {
                    if (a[i][0] < b[j][0])
                    {
                        ab.push(a[i]);
                        ++i;
                    }
                    else if (a[i][0] > b[j][0])
                    {
                        ab.push(b[j]);
                        ++j;
                    }
                    else
                    {
                        ab.push(a[i]);
                        ++i; ++j;
                        intersect = 1;
                    }
                }
                while (i < n)
                {
                    ab.push(a[i]);
                    ++i;
                }
                while (j < m)
                {
                    ab.push(b[j]);
                    ++j;
                }
            }
            return [ab, intersect];
        };
        match = async function match(i, j, j0) {
            if (i >= nterms) return null; // end of match
            var term = terms[i], l,
                best = null, max_score = -200000,
                ngram, matcher, index, key, ii, iic,
                entry, k, word, res,
                similarity, score, marks,
                intersections = 0;
            ngram = seeker._ngram(term, N);
            l = term.length;
            k = round((1-threshold)*l);
            index = [];
            for (key in ngram)
            {
                if (!isset(ngram, key)) continue;
                res = merge(index, await get_index(key), j);
                index = res[0];
                intersections += res[1];
            }
            if (!index.length || (l-N-intersections > k)) return false; // no match
            matcher = new LiteSeek.Automaton(term, k);
            for (ii=0,iic=index.length; ii<iic; ++ii)
            {
                entry = index[ii];
                k = entry[0]; // order in doc of next word
                if (consecutive && (0 < i) && (k > j0+i)) break; // consecutive and no consecutive match, stop
                word = entry[1]; // word at this point

                // try to match this term
                similarity = (word === term) ? 1 : (exact ? 0 : matcher.match(word));
                if (threshold > similarity) continue; // not good match

                // try to match rest terms
                res = await match(i+1, k+1, 0 === i ? k : j0);
                if (false !== res)
                {
                    // matched
                    score = j - k - (1 - similarity)*10;
                    marks = [[entry[2], entry[3]]]; // marks of this match in document
                    if (res)
                    {
                        score += res.score;
                        marks = marks.concat(res.marks);
                    }
                    if (score > max_score)
                    {
                        // current best match
                        max_score = score;
                        best = {
                            score : score,
                            marks : marks
                        };
                    }
                }
            }
            return (0 < i) && !best ? false : best;
        };
        res = await match(0, -1, -1);
        return res ? res : {score : -2000000, marks : []};
    },

    _ngram: function(s, n) {
        var c = max(1, s.length - n + 1), ngram = {}, i, k;
        for (i=0; i<c; ++i)
        {
            k = s.slice(i, i+n);
            if (!isset(ngram, k)) ngram[k] = 0;//[];
            ngram[k] += 1;//.push(i);
        }
        return ngram;
    },

    normalize: function(string, locale) {
        return this.normalizeAccents(string.toLowerCase(), locale);
    },

    normalizeAccents: function(string, locale) {
        // normalize some common utf8 character accents
        // Adapted from WordPress
        // https://github.com/WordPress/WordPress/blob/master/wp-includes/formatting.php
        if (LiteSeek.ASCII.test(string)) return string;

        var map_ = {
            // Decompositions for Latin-1 Supplement
            'ª' : 'a',
            'º' : 'o',
            'À' : 'A',
            'Á' : 'A',
            'Â' : 'A',
            'Ã' : 'A',
            'Ä' : 'A',
            'Å' : 'A',
            'Ç' : 'C',
            'È' : 'E',
            'É' : 'E',
            'Ê' : 'E',
            'Ë' : 'E',
            'Ì' : 'I',
            'Í' : 'I',
            'Î' : 'I',
            'Ï' : 'I',
            'Ð' : 'D',
            'Ñ' : 'N',
            'Ò' : 'O',
            'Ó' : 'O',
            'Ô' : 'O',
            'Õ' : 'O',
            'Ö' : 'O',
            'Ù' : 'U',
            'Ú' : 'U',
            'Û' : 'U',
            'Ü' : 'U',
            'Ý' : 'Y',
            'ß' : 's',
            'à' : 'a',
            'á' : 'a',
            'â' : 'a',
            'ã' : 'a',
            'ä' : 'a',
            'å' : 'a',
            'ç' : 'c',
            'è' : 'e',
            'é' : 'e',
            'ê' : 'e',
            'ë' : 'e',
            'ì' : 'i',
            'í' : 'i',
            'î' : 'i',
            'ï' : 'i',
            'ð' : 'd',
            'ñ' : 'n',
            'ò' : 'o',
            'ó' : 'o',
            'ô' : 'o',
            'õ' : 'o',
            'ö' : 'o',
            'ø' : 'o',
            'ù' : 'u',
            'ú' : 'u',
            'û' : 'u',
            'ü' : 'u',
            'ý' : 'y',
            'ÿ' : 'y',
            'Ø' : 'O',
            // Decompositions for Latin Extended-A
            'Ā' : 'A',
            'ā' : 'a',
            'Ă' : 'A',
            'ă' : 'a',
            'Ą' : 'A',
            'ą' : 'a',
            'Ć' : 'C',
            'ć' : 'c',
            'Ĉ' : 'C',
            'ĉ' : 'c',
            'Ċ' : 'C',
            'ċ' : 'c',
            'Č' : 'C',
            'č' : 'c',
            'Ď' : 'D',
            'ď' : 'd',
            'Đ' : 'D',
            'đ' : 'd',
            'Ē' : 'E',
            'ē' : 'e',
            'Ĕ' : 'E',
            'ĕ' : 'e',
            'Ė' : 'E',
            'ė' : 'e',
            'Ę' : 'E',
            'ę' : 'e',
            'Ě' : 'E',
            'ě' : 'e',
            'Ĝ' : 'G',
            'ĝ' : 'g',
            'Ğ' : 'G',
            'ğ' : 'g',
            'Ġ' : 'G',
            'ġ' : 'g',
            'Ģ' : 'G',
            'ģ' : 'g',
            'Ĥ' : 'H',
            'ĥ' : 'h',
            'Ħ' : 'H',
            'ħ' : 'h',
            'Ĩ' : 'I',
            'ĩ' : 'i',
            'Ī' : 'I',
            'ī' : 'i',
            'Ĭ' : 'I',
            'ĭ' : 'i',
            'Į' : 'I',
            'į' : 'i',
            'İ' : 'I',
            'ı' : 'i',
            'Ĵ' : 'J',
            'ĵ' : 'j',
            'Ķ' : 'K',
            'ķ' : 'k',
            'ĸ' : 'k',
            'Ĺ' : 'L',
            'ĺ' : 'l',
            'Ļ' : 'L',
            'ļ' : 'l',
            'Ľ' : 'L',
            'ľ' : 'l',
            'Ŀ' : 'L',
            'ŀ' : 'l',
            'Ł' : 'L',
            'ł' : 'l',
            'Ń' : 'N',
            'ń' : 'n',
            'Ņ' : 'N',
            'ņ' : 'n',
            'Ň' : 'N',
            'ň' : 'n',
            'ŉ' : 'n',
            'Ŋ' : 'N',
            'ŋ' : 'n',
            'Ō' : 'O',
            'ō' : 'o',
            'Ŏ' : 'O',
            'ŏ' : 'o',
            'Ő' : 'O',
            'ő' : 'o',
            'Ŕ' : 'R',
            'ŕ' : 'r',
            'Ŗ' : 'R',
            'ŗ' : 'r',
            'Ř' : 'R',
            'ř' : 'r',
            'Ś' : 'S',
            'ś' : 's',
            'Ŝ' : 'S',
            'ŝ' : 's',
            'Ş' : 'S',
            'ş' : 's',
            'Š' : 'S',
            'š' : 's',
            'Ţ' : 'T',
            'ţ' : 't',
            'Ť' : 'T',
            'ť' : 't',
            'Ŧ' : 'T',
            'ŧ' : 't',
            'Ũ' : 'U',
            'ũ' : 'u',
            'Ū' : 'U',
            'ū' : 'u',
            'Ŭ' : 'U',
            'ŭ' : 'u',
            'Ů' : 'U',
            'ů' : 'u',
            'Ű' : 'U',
            'ű' : 'u',
            'Ų' : 'U',
            'ų' : 'u',
            'Ŵ' : 'W',
            'ŵ' : 'w',
            'Ŷ' : 'Y',
            'ŷ' : 'y',
            'Ÿ' : 'Y',
            'Ź' : 'Z',
            'ź' : 'z',
            'Ż' : 'Z',
            'ż' : 'z',
            'Ž' : 'Z',
            'ž' : 'z',
            'ſ' : 's',
            // Decompositions for Latin Extended-B
            'Ș' : 'S',
            'ș' : 's',
            'Ț' : 'T',
            'ț' : 't',
            // Vowels with diacritic (Vietnamese)
            // unmarked
            'Ơ' : 'O',
            'ơ' : 'o',
            'Ư' : 'U',
            'ư' : 'u',
            // grave accent
            'Ầ' : 'A',
            'ầ' : 'a',
            'Ằ' : 'A',
            'ằ' : 'a',
            'Ề' : 'E',
            'ề' : 'e',
            'Ồ' : 'O',
            'ồ' : 'o',
            'Ờ' : 'O',
            'ờ' : 'o',
            'Ừ' : 'U',
            'ừ' : 'u',
            'Ỳ' : 'Y',
            'ỳ' : 'y',
            // hook
            'Ả' : 'A',
            'ả' : 'a',
            'Ẩ' : 'A',
            'ẩ' : 'a',
            'Ẳ' : 'A',
            'ẳ' : 'a',
            'Ẻ' : 'E',
            'ẻ' : 'e',
            'Ể' : 'E',
            'ể' : 'e',
            'Ỉ' : 'I',
            'ỉ' : 'i',
            'Ỏ' : 'O',
            'ỏ' : 'o',
            'Ổ' : 'O',
            'ổ' : 'o',
            'Ở' : 'O',
            'ở' : 'o',
            'Ủ' : 'U',
            'ủ' : 'u',
            'Ử' : 'U',
            'ử' : 'u',
            'Ỷ' : 'Y',
            'ỷ' : 'y',
            // tilde
            'Ẫ' : 'A',
            'ẫ' : 'a',
            'Ẵ' : 'A',
            'ẵ' : 'a',
            'Ẽ' : 'E',
            'ẽ' : 'e',
            'Ễ' : 'E',
            'ễ' : 'e',
            'Ỗ' : 'O',
            'ỗ' : 'o',
            'Ỡ' : 'O',
            'ỡ' : 'o',
            'Ữ' : 'U',
            'ữ' : 'u',
            'Ỹ' : 'Y',
            'ỹ' : 'y',
            // acute accent
            'Ấ' : 'A',
            'ấ' : 'a',
            'Ắ' : 'A',
            'ắ' : 'a',
            'Ế' : 'E',
            'ế' : 'e',
            'Ố' : 'O',
            'ố' : 'o',
            'Ớ' : 'O',
            'ớ' : 'o',
            'Ứ' : 'U',
            'ứ' : 'u',
            // dot below
            'Ạ' : 'A',
            'ạ' : 'a',
            'Ậ' : 'A',
            'ậ' : 'a',
            'Ặ' : 'A',
            'ặ' : 'a',
            'Ẹ' : 'E',
            'ẹ' : 'e',
            'Ệ' : 'E',
            'ệ' : 'e',
            'Ị' : 'I',
            'ị' : 'i',
            'Ọ' : 'O',
            'ọ' : 'o',
            'Ộ' : 'O',
            'ộ' : 'o',
            'Ợ' : 'O',
            'ợ' : 'o',
            'Ụ' : 'U',
            'ụ' : 'u',
            'Ự' : 'U',
            'ự' : 'u',
            'Ỵ' : 'Y',
            'ỵ' : 'y',
            // Vowels with diacritic (Chinese, Hanyu Pinyin)
            'ɑ' : 'a',
            // macron
            'Ǖ' : 'U',
            'ǖ' : 'u',
            // acute accent
            'Ǘ' : 'U',
            'ǘ' : 'u',
            // caron
            'Ǎ' : 'A',
            'ǎ' : 'a',
            'Ǐ' : 'I',
            'ǐ' : 'i',
            'Ǒ' : 'O',
            'ǒ' : 'o',
            'Ǔ' : 'U',
            'ǔ' : 'u',
            'Ǚ' : 'U',
            'ǚ' : 'u',
            // grave accent
            'Ǜ' : 'U',
            'ǜ' : 'u',
            // modern greek accents
            'Ά' : 'Α',
            'ά' : 'α',
            'Έ' : 'Ε',
            'έ' : 'ε',
            'Ή' : 'Η',
            'ή' : 'η',
            'Ί' : 'Ι',
            'ί' : 'ι',
            'Ϊ' : 'Ι',
            'ϊ' : 'ι',
            'ΐ' : 'ι',
            'Ό' : 'Ο',
            'ό' : 'ο',
            'Ύ' : 'Υ',
            'ύ' : 'υ',
            'Ϋ' : 'Υ',
            'ϋ' : 'υ',
            'ΰ' : 'υ',
            'Ώ' : 'Ω',
            'ώ' : 'ω',
            'ς' : 'σ'
        };

        // Used for locale-specific rules
        if ('ca' === locale)
        {
            map_['l·l'] = 'll';
        }

        return Object.keys(map_).reduce(function(string, c) {
            return string.split(c).join(map_[c]);
        }, string);
    }
};

function LiteSeekAutomaton(word, maxk)
{
    var self = this;
    self.w = String(word);
    self.n = self.w.length;
    self.k = min(max((null == maxk ? 1 : maxk)|0, 0), self.n);
}
LiteSeekAutomaton.prototype = {
    constructor: LiteSeekAutomaton,

    w: '',
    n: 0,
    k: 1,

    initial: function() {
        // only diagonals up to k max errors
        return [
            range(0, this.k, 1),
            range(0, this.k, 1),
            [],
            [],
            ''
        ];
    },

    transition: function(s, c) {
        // damerau-levenshtein algorithm step-by-step
        var self = this,
            k = self.k,
            w = self.w,
            n = self.n,
            index = s[0],
            value = s[1],
            new_index = [],
            new_value = [],
            m = index.length,
            index_2 = s[2],
            value_2 = s[3],
            m2 = index_2.length,
            prev_i = -1,
            prev_v = 0,
            next_i = -1,
            i, j, j2 = 0,
            v, d, cp = s[4]
        ;
        if ((0 < m) && (0 === index[0]) && (value[0] < k))
        {
            i = 0;
            v = value[0] + 1;
            prev_i = i;
            prev_v = v;
            new_index.push(i);
            new_value.push(v);
        }
        for (j=0; j<m; ++j)
        {
            i = index[j];
            if (i >= n) break;
            d = w.charAt(i) === c ? 0 : 1;
            v = value[j] + d; // L[i,ii] = L[i-1,ii-1] + d
            next_i = j+1 < m ? index[j+1] : -1;
            ++i;
            if (i-1 === prev_i)
            {
                v = min(v, prev_v + 1); // L[i,ii] = min(L[i,ii], L[i-1,ii] + 1)
            }
            if (i === next_i)
            {
                v = min(v, value[j+1] + 1); // L[i,ii] = min(L[i,ii], L[i,ii-1] + 1)
            }
            if ((cp === w.charAt(i-1)) && (c === w.charAt(i-2)))
            {
                while ((j2 < m2) && (index_2[j2] < i-2)) ++j2;
                if ((j2 < m2) && (i-2 === index_2[j2]))
                {
                    v = min(v, value_2[j2] + d); // L[i,ii] = min(L[i,ii], L[i-2,ii-2] + d)
                    ++j2;
                }
            }
            if (v <= k)
            {
                prev_i = i;
                prev_v = v;
                new_index.push(i);
                new_value.push(v);
            }
        }
        return [
            new_index,
            new_value,
            index,
            value,
            c
        ];
    },

    terminal: function(s) {
        var index = s[0], n = index.length;
        return (0 < n) && (index[n-1] === this.n);
    },

    match: function(word, state) {
        var self = this, i, n, char;
        if (null == state) state = self.initial();
        for (i=0,n=word.length; i<n; ++i)
        {
            char = word.charAt(i);
            state = self.transition(state, char);
            if (!state[0].length) return 0; // no match
        }
        return self.terminal(state) ? (1 - state[1][state[1].length-1]/self.n) : 0
    }
};
LiteSeek.Automaton = LiteSeekAutomaton;

// utils
var stdMath = Math,
    min = stdMath.min,
    max = stdMath.max,
    floor = stdMath.floor,
    round = stdMath.round,
    HAS = Object.prototype.hasOwnProperty,
    toString = Object.prototype.toString;

function is_callable(x)
{
    return "function" === typeof x;
}
function is_string(x)
{
    return '[object String]' === toString.call(x);
}
function is_obj(x)
{
    return '[object Object]' === toString.call(x);
}
function is_array(x)
{
    return '[object Array]' === toString.call(x);
}
function isset(o, x)
{
    return HAS.call(o, x);
}
function range(start, end, step)
{
    if (null == step) step = 1;
    var range = [];
    while (start <= end)
    {
        range.push(start);
        start += step;
    }
    return range;
}

// export it
return LiteSeek;
});

