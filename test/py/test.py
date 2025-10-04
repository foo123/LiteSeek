# -*- coding: utf-8 -*-
import os, sys

DIR = os.path.dirname(os.path.abspath(__file__))

def import_module(name, path):
    #import imp
    #try:
    #    mod_fp, mod_path, mod_desc  = imp.find_module(name, [path])
    #    mod = getattr( imp.load_module(name, mod_fp, mod_path, mod_desc), name )
    #except ImportError as exc:
    #    mod = None
    #    sys.stderr.write("Error: failed to import module ({})".format(exc))
    #finally:
    #    if mod_fp: mod_fp.close()
    #return mod
    import importlib.util, sys
    spec = importlib.util.spec_from_file_location(name, path+name+'.py')
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return getattr(mod, name)

# import the LiteSeek.py (as a) module, probably you will want to place this in another dir/package
LiteSeek = import_module('LiteSeek', os.path.join(DIR, '../../src/py/'))
if not LiteSeek:
    print ('Could not load the LiteSeek Module')
    sys.exit(1)

def display(query, results):
    print("\n[" + query + "] -> ")
    for match in results:
        doc = match['document']
        marks = match['marks']
        for i in range(len(marks)-1, -1, -1):
            mark = marks[i]
            doc = doc[0:mark[0]] + '**' + doc[mark[0]:mark[0]+mark[1]] + '**' + doc[mark[0]+mark[1]:]
        print("\n\n" + doc)
    print("--\n")

def test():
    document_en = u"""Friendship any contrasted may solicitude mention insipidity in introduced literature it. He seemed denote except as oppose do spring my. Between any may mention evening age shortly can ability regular. He shortly sixteen of colonel colonel evening cordial to. Although jointure an my of mistress servants am weddings. Age why the therefore education unfeeling for arranging. Above again money own scale maids ham least led. Returned settling produced strongly ecstatic use yourself way. Repulsive extremity enjoyment she perceived nor.

Prepared is me marianne pleasure likewise debating. Wonder an unable except better stairs do ye admire. His and eat secure sex called esteem praise. So moreover as speedily differed branched ignorant. Tall are her knew poor now does then. Procured to contempt oh he raptures amounted occasion. One boy assure income spirit lovers set."""

    document_fr = u"""Le client est très important merci, le client sera suivi par le client. Énée n'a pas de justice, pas de résultat, pas de ligula, et la vallée veut la sauce. Mais, beaucoup de temps ne pas maintenant. Morbi mais qui veut vendre une couche de contenu triste d'internet. Être ivre maintenant, mais ne pas maintenant, mon urne est d'une grande beauté, mais elle n'est pas aussi bien faite que dans un livre. Mécène dans la vallée de l'orc, dans l'élément même. Certaines des exigences faciles du budget, qu'il soit beaucoup de temps pour dignissim et. Je ne m'en fais pas chez moi, ça va être moche dans le vestibule. Mais aussi des protéines de Pour avant la fin de la semaine, qui connaît le poison, le résultat.

Pour un football doux Je ne pense pas qu'il soit facile de tomber malade avec de la levure ou de la levure. Le bateau et de la terre. Il est basketteur et agent immobilier. Mais quel genre de politique, qui parfois masse. Le cours convient aux commanditaires qui se tournent vers les rivages à travers nos mariages, à travers les projets hyménéens. On dit qu'il habitait cette rue. Il n'y a aucune conséquence sur l'importance de la vie. Parfois, la faim est attendue et avant c'est la première chose dans la gorge. Les enfants lisent le livre, le passeur et pas de flèche, la flèche est le plus gros joueur."""

    search = LiteSeek()

    query1en = u'ointur sevrants weddings'
    query2en = u'any may mention'
    query1fr = u'tre impotrant suivi client'
    query2fr = u'mais ne pas maintenant'

    res1en = search.find(document_en, query1en, False, False)
    res2en = search.find(document_en, query1en, True, False)
    res3en = search.find(document_en, query2en, False, True)
    res1fr = search.find(document_fr, query1fr, False, False)
    res2fr = search.find(document_fr, query1fr, True, False)
    res3fr = search.find(document_fr, query2fr, False, True)

    display(query1en, res1en)
    display(query1en, res2en)
    display(query2en, res3en)

    display(query1fr, res1fr)
    display(query1fr, res2fr)
    display(query2fr, res3fr)

# UTF8 BOM
UTF8_BOM = b"\xEF\xBB\xBF"
sys.stdout.reconfigure(encoding='utf-8')
sys.stdout.buffer.write(UTF8_BOM)
sys.stdout.flush()

test()