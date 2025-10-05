<?php
include(dirname(__FILE__) . '/../../src/php/LiteSeek.php');

function display($query, $results)
{
    echo "\n[" . $query . "] -> \n";
    foreach ($results as $match)
    {
        $doc = $match['document'];
        foreach (array_reverse($match['marks']) as $mark)
        {
            $doc = mb_substr($doc, 0, $mark[0], 'UTF-8') . '**' . mb_substr($doc, $mark[0], $mark[1], 'UTF-8') . '**' . mb_substr($doc, $mark[0]+$mark[1], null, 'UTF-8');
        }
        echo "\n\n" . $doc . "\n";
    }
    echo "--\n";
}
function test()
{
    $document_en = 'Friendship any contrasted may solicitude mention insipidity in introduced literature it. He seemed denote except as oppose do spring my. Between any may mention evening age shortly can ability regular. He shortly sixteen of colonel colonel evening cordial to. Although jointure an my of mistress servants am weddings. Age why the therefore education unfeeling for arranging. Above again money own scale maids ham least led. Returned settling produced strongly ecstatic use yourself way. Repulsive extremity enjoyment she perceived nor.

Prepared is me marianne pleasure likewise debating. Wonder an unable except better stairs do ye admire. His and eat secure sex called esteem praise. So moreover as speedily differed branched ignorant. Tall are her knew poor now does then. Procured to contempt oh he raptures amounted occasion. One boy assure income spirit lovers set.';

    $document_fr = 'Le client est très important merci, le client sera suivi par le client. Énée n\'a pas de justice, pas de résultat, pas de ligula, et la vallée veut la sauce. Mais, beaucoup de temps ne pas maintenant. Morbi mais qui veut vendre une couche de contenu triste d\'internet. Être ivre maintenant, mais ne pas maintenant, mon urne est d\'une grande beauté, mais elle n\'est pas aussi bien faite que dans un livre. Mécène dans la vallée de l\'orc, dans l\'élément même. Certaines des exigences faciles du budget, qu\'il soit beaucoup de temps pour dignissim et. Je ne m\'en fais pas chez moi, ça va être moche dans le vestibule. Mais aussi des protéines de Pour avant la fin de la semaine, qui connaît le poison, le résultat.

Pour un football doux Je ne pense pas qu\'il soit facile de tomber malade avec de la levure ou de la levure. Le bateau et de la terre. Il est basketteur et agent immobilier. Mais quel genre de politique, qui parfois masse. Le cours convient aux commanditaires qui se tournent vers les rivages à travers nos mariages, à travers les projets hyménéens. On dit qu\'il habitait cette rue. Il n\'y a aucune conséquence sur l\'importance de la vie. Parfois, la faim est attendue et avant c\'est la première chose dans la gorge. Les enfants lisent le livre, le passeur et pas de flèche, la flèche est le plus gros joueur.';

    $search = (new LiteSeek())->option('similarity', 0.65)->option('n-gram', 3);

    $query1en = 'ointur sevrants weddings';
    $query2en = 'any may mention';
    $query1fr = 'tre impotrant suivi client';
    $query2fr = 'mais ne pas maintenant';

    $res1en = $search->find($document_en, $query1en, false, false);
    $res2en = $search->find($document_en, $query1en, true, false);
    $res3en = $search->find($document_en, $query2en, false, true);
    $res1fr = $search->find($document_fr, $query1fr, false, false);
    $res2fr = $search->find($document_fr, $query1fr, true, false);
    $res3fr = $search->find($document_fr, $query2fr, false, true);

    display($query1en, $res1en);
    display($query1en, $res2en);
    display($query2en, $res3en);

    display($query1fr, $res1fr);
    display($query1fr, $res2fr);
    display($query2fr, $res3fr);
}

// UTF8 BOM
define('UTF8_BOM', chr(0xEF).chr(0xBB).chr(0xBF));
echo UTF8_BOM;

test();