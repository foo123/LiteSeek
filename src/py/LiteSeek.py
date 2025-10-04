##
#  LiteSeek
#  A lite, fast and versatile fuzzy full-text search engine for PHP, JavaScript, Python
#
#  @version: 1.0.0
#  https://github.com/foo123/LiteSeek
#
##
# -*- coding: utf-8 -*-
import re
from functools import cmp_to_key

def NOP(*args):
    return None

class LiteSeek:
    """
    LiteSeek for Python,
    https://github.com/foo123/LiteSeek
    """
    VERSION = "1.0.0"

    DELIM = re.compile(r'[\s\.\?,;!:\(\)\[\]@#\$%\^&\*\-_\+<>=\/\\"\']')
    SPACE = re.compile(r'\s+')
    ASCII = re.compile(r'^[ -~]+$')

    def __init__(self):
        # some defaults
        self.opts = {}
        self.option('match-prefix', False)
        self.option('similarity', 0.6)
        self.option('n-gram', 2)
        self.option('filter_word', None)
        self.option('normalize_word', None)
        self.option('read_index', NOP)
        self.option('store_index', NOP)

    def option(self, *args):
        nargs = len(args)
        if 1 == nargs:
            key = str(args[0])
            return self.opts[key] if key in self.opts else None
        elif 1 < nargs:
            key = str(args[0])
            val = args[1]
            self.opts[key] = val
        return self

    def index(self, documentText, documentId, locale = None):
        filter_word = self.option('filter_word')
        normalize_word = self.option('normalize_word')
        if not callable(filter_word): filter_word = None
        if not callable(normalize_word): normalize_word = None
        s = str(documentText)
        l = len(s)
        N = self.option('n-gram')
        documentIndex = {}
        w = ''
        j = 0
        p = 0
        for i in range(l):
            c = s[i]
            # if delimiter bypass
            if LiteSeek.DELIM.match(c):
                if '' != w:
                    if (not filter_word) or filter_word(w, locale):
                        n = len(w)
                        w = normalize_word(w, locale) if normalize_word else self.normalize(w, locale)
                        ngram = self._ngram(w, N);
                        for k in ngram:
                            if k not in documentIndex:
                                documentIndex[k] = []
                            #                        order, word, startpos, len
                            documentIndex[k].append([p,     w,    j,        n])
                        p += 1
                w = ''
            else:
                if '' == w: j = i
                w += c

        if '' != w:
            if (not filter_word) or filter_word(w, locale):
                n = len(w)
                w = normalize_word(w, locale) if normalize_word else self.normalize(w, locale)
                ngram = self._ngram(w, N);
                for k in ngram:
                    if k not in documentIndex:
                        documentIndex[k] = []
                    #                        order, word, startpos, len
                    documentIndex[k].append([p,     w,    j,        n])
                p += 1
        if documentId:
            self.option('store_index')(documentId, documentIndex, locale)
        return documentIndex

    def find(self, documents, query, exact = False, consecutive = False, locale = None):
        index = self.index(documents, None) if is_string(documents) else None
        results = []
        if index or (is_array(documents) and len(documents)):
            exact = bool(exact)
            consecutive = bool(consecutive)

            words = list(filter(
                lambda s: 0 < len(s),
                re.split(
                    LiteSeek.SPACE,
                    # strip delimiters ..
                    re.sub(
                        LiteSeek.DELIM,
                        ' ',
                        str(query)
                    ).strip()
                )
            ))
            terms = []

            filter_word = self.option('filter_word')
            normalize_word = self.option('normalize_word')
            if not callable(filter_word): filter_word = None
            if not callable(normalize_word): normalize_word = None

            for word in words:
                if filter_word and not filter_word(word, locale):
                    continue
                terms.append(normalize_word(word, locale) if normalize_word else self.normalize(word, locale))

            if not len(terms): return results

            t = -1000000

            if index:
                res = self._match(None, terms, exact, consecutive, locale, index)
                if t < res['score']:
                    results.append({
                        'document'  : documents,
                        'query'     : query,
                        'score'     : res['score'],
                        'marks'     : res['marks']
                    })
            else:
                for d in documents:
                    res = self._match(d, terms, exact, consecutive, locale, None)
                    if t < res['score']:
                        results.append({
                            'document'  : d,
                            'query'     : query,
                            'score'     : res['score'],
                            'marks'     : res['marks']
                        })

            results = list(sorted(results, key=cmp_to_key(lambda a, b: b['score'] - a['score'])))
        return results

    def _match(self, document, terms, exact = False, consecutive = False, locale = None, document_index = None):
        seeker = self
        threshold = seeker.option('similarity')
        N = seeker.option('n-gram')
        nterms = len(terms)
        index = {} # cache

        def get_index(key):
            nonlocal index
            nonlocal document_index
            if document_index:
                return document_index[key] if key in document_index else None
            elif index and (key in index):
                return index[key]
            else:
                read_index = seeker.option('read_index')(document, key, locale)
                if is_dict(read_index):
                    # whole index returned, store it
                    index = None
                    document_index = read_index
                    return document_index[key] if key in document_index else None
                else:
                    # index for key returned, cache it
                    index[key] = read_index
                    return read_index

        def merge(a, b, pos):
            intersect = 0
            if not b:
                ab = a
            else:
                ab = []
                n = len(a)
                m = len(b)
                i = 0
                j = 0
                #while i < n and a[i][0] < pos: i += 1
                while j < m and b[j][0] < pos: j += 1
                while i < n and j < m:
                    if a[i][0] < b[j][0]:
                        ab.append(a[i])
                        i += 1
                    elif a[i][0] > b[j][0]:
                        ab.append(b[j])
                        j += 1
                    else:
                        ab.append(a[i])
                        i += 1
                        j += 1
                        intersect = 1
                while i < n:
                    ab.append(a[i])
                    i += 1
                while j < m:
                    ab.append(b[j])
                    j += 1
            return (ab, intersect)

        def match(i, j, j0):
            if i >= nterms: return None # end of match
            term = terms[i]
            ngram = seeker._ngram(term, N)
            l = len(term)
            k = round((1-threshold)*l)
            index = []
            intersections = 0
            for key in ngram:
                index, intersect = merge(index, get_index(key), j)
                intersections += intersect
            if (not index) or (l-N-intersections > k): return False # no match
            matcher = LiteSeek.Automaton(term, k)
            best = None
            max_score = -200000
            for entry in index:
                k = entry[0] # order in doc of next word
                if consecutive and (0 < i) and (k > j0+i): break # consecutive and no consecutive match, stop
                word = entry[1] # word at this point

                # try to match this term
                similarity = 1 if (word == term) else (0 if exact else matcher.match(word))
                if threshold > similarity: continue # not good match

                # try to match rest terms
                res = match(i+1, k+1, k if 0 == i else j0)
                if res is not False:
                    # matched
                    score = j - k - (1 - similarity)*10
                    marks = [[entry[2], entry[3]]] # marks of this match in document
                    if res:
                        score += res['score']
                        marks = marks + res['marks']
                    if score > max_score:
                        # current best match
                        max_score = score
                        best = {
                            'score' : score,
                            'marks' : marks
                        }
            return False if (0 < i) and not best else best

        res = match(0, -1, -1)
        return res if res else {'score' : -2000000, 'marks' : []}

    def _ngram(self, s, n):
        c = max(1, len(s) - n + 1)
        ngram = {}
        for i in range(c):
            k = s[i:i+n]
            if k not in ngram: ngram[k] = 0#[]
            ngram[k] += 1#.append(i)
        return ngram

    def normalize(self, string, locale = None):
        return self.normalizeAccents(string.lower(), locale)

    def normalizeAccents(self, string, locale = None):
        # normalize some common utf8 character accents
        # Adapted from WordPress
        # https://github.com/WordPress/WordPress/blob/master/wp-includes/formatting.php
        if LiteSeek.ASCII.match(string): return string

        map_ = {
            # Decompositions for Latin-1 Supplement
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
            # Decompositions for Latin Extended-A
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
            # Decompositions for Latin Extended-B
            'Ș' : 'S',
            'ș' : 's',
            'Ț' : 'T',
            'ț' : 't',
            # Vowels with diacritic (Vietnamese)
            # unmarked
            'Ơ' : 'O',
            'ơ' : 'o',
            'Ư' : 'U',
            'ư' : 'u',
            # grave accent
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
            # hook
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
            # tilde
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
            # acute accent
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
            # dot below
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
            # Vowels with diacritic (Chinese, Hanyu Pinyin)
            'ɑ' : 'a',
            # macron
            'Ǖ' : 'U',
            'ǖ' : 'u',
            # acute accent
            'Ǘ' : 'U',
            'ǘ' : 'u',
            # caron
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
            # grave accent
            'Ǜ' : 'U',
            'ǜ' : 'u',
            # modern greek accents
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
        }

        # Used for locale-specific rules
        if 'ca' == locale:
            map_['l·l'] = 'll'

        for c in map_: string = map_[c].join(string.split(c))
        return string


class LiteSeekAutomaton:

    def __init__(self, word, maxk = 1):
        self.w = str(word)
        self.n = len(self.w)
        self.k = min(max(int(maxk), 0), self.n)

    def initial(self):
        # only diagonals up to k max errors
        return (
            list(range(0, self.k+1, 1)),
            list(range(0, self.k+1, 1)),
            [],
            [],
            ''
        )

    def transition(self, s, c):
        # damerau-levenshtein algorithm step-by-step
        k = self.k
        w = self.w
        n = self.n
        index = s[0]
        value = s[1]
        new_index = []
        new_value = []
        m = len(index)
        prev_i = -1
        prev_v = 0
        next_i = -1
        index_2 = s[2]
        value_2 = s[3]
        cp = s[4]
        m2 = len(index_2)
        j2 = 0
        if (0 < m) and (0 == index[0]) and (value[0] < k):
            i = 0
            v = value[0] + 1
            prev_i = i
            prev_v = v
            new_index.append(i)
            new_value.append(v)

        for j, i in enumerate(index):
            if i >= n: break
            d = 0 if w[i] == c else 1
            v = value[j] + d # L[i,ii] = L[i-1,ii-1] + d
            next_i = index[j+1] if j+1 < m else -1
            i += 1
            if i-1 == prev_i:
                v = min(v, prev_v + 1) # L[i,ii] = min(L[i,ii], L[i-1,ii] + 1)
            if i == next_i:
                v = min(v, value[j+1] + 1) # L[i,ii] = min(L[i,ii], L[i,ii-1] + 1)
            if (cp == w[i-1]) and (c == w[i-2]):
                while (j2 < m2) and (index_2[j2] < i-2): j2 += 1
                if (j2 < m2) and (i-2 == index_2[j2]):
                    v = min(v, value_2[j2] + d) # L[i,ii] = min(L[i,ii], L[i-2,ii-2] + d)
                    j2 += 1
            if v <= k:
                prev_i = i
                prev_v = v
                new_index.append(i)
                new_value.append(v)
        return (
            new_index,
            new_value,
            index,
            value,
            c
        )

    def terminal(self, s):
        index = s[0]
        n = len(index)
        return (0 < n) and (index[n-1] == self.n)

    def match(self, word, state = None):
        if state is None: state = self.initial()
        for char in word:
            state = self.transition(state, char)
            if not state[0]: return 0 # no match
        return (1 - state[1][-1]/self.n) if self.terminal(state) else 0

LiteSeek.Automaton = LiteSeekAutomaton

# utils
def is_string(x):
    return isinstance(x, str)

def is_array(x):
    return isinstance(x, (list, tuple))

def is_dict(x):
    return isinstance(x, dict)

__all__ = ['LiteSeek']

