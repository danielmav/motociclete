<?php
declare(strict_types=1);
// Seeds the "Despre noi" page: intro text (settings), showroom gallery,
// team members and the history timeline. Idempotent (only fills empty tables;
// settings via INSERT IGNORE so admin edits are preserved). UTF-8 file.
// Run with Laragon PHP 8.1:
//   C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe database/seed_about.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_dbutil.php';
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}
$settings = require __DIR__ . '/../config/settings.php';
$pdo = (new App\Database($settings['db']))->local();
run_sql_file($pdo, __DIR__ . '/schema_pages.sql');

// --- Intro (settings keys) --------------------------------------------------
$intro = <<<HTML
<p>Faptele vorbesc de la sine. 23 ani de excelență sună prea pretențios și ne place să credem că ceea ce facem noi este mai mult decât un simplu business. Este un stil de viață. Așa că preferăm să folosim termeni precum aventură, socializare, căutare, risc, satisfacție, iar lista rămâne deschisă. Ce am făcut așadar în ultimii 23 ani?</p>
<p>„Firma a fost înființată în 1997, dar business-ul efectiv a pornit în 2003, când am deschis în Bulevardul Ghencea, la parterul unui bloc, un magazin de 100 metri pătrați", își aduce aminte Ciprian Popescu, sportiv și om de afaceri, greu de ghicit în ce ordine ar trebui puse cele două ocupații. La fel ca pentru orice pilot de motociclete, în spatele său se află o echipă de zece oameni, pe care Ciprian nu îi numește angajați, ci cărora le spune direct, colegi. Iar în proporție de 80%, această echipă a rămas aceeași, de zece ani. „În 2009, anul în care piața moto din România a trecut practic printr-un șoc, am făcut toate eforturile să nu disponibilizez oameni din echipa Dual Motors", spune Ciprian.</p>
HTML;
$set = $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (:k, :v)");
$set->execute([':k' => 'about_heading', ':v' => '23 de ani pe două roți']);
$set->execute([':k' => 'about_intro_html', ':v' => $intro]);
echo "about intro ensured\n";

// --- Showroom gallery -------------------------------------------------------
$gallery = [
    'Dual-Motors-Team-2024.jpeg',
    'showroom-2024-1.jpeg', 'showroom-2024-2.jpeg', 'showroom-2024-3.jpeg', 'showroom-2024-4.jpeg',
    'showroom-dual-motors-bucuresti-2020-1.jpg', 'showroom-dual-motors-bucuresti-2020-2.jpg',
    'showroom-dual-motors-bucuresti-2020-4.jpg', 'showroom-dual-motors-bucuresti-2020-5.jpg',
    'showroom-dual-motors-bucuresti-2020-7.jpg', 'showroom-dual-motors-bucuresti-2020-9.jpg',
    'showroom-dual-motors-bucuresti-2020-11.jpg', 'showroom-dual-motors-bucuresti-2020-12.jpg',
];
if ((int) $pdo->query('SELECT COUNT(*) FROM about_images')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO about_images (filename, position) VALUES (?, ?)');
    foreach ($gallery as $i => $f) {
        $st->execute([$f, $i]);
    }
    echo count($gallery) . " gallery images seeded\n";
}

// --- Team members -----------------------------------------------------------
$team = [
    ['Ciprian Popescu', 'Director General', '0729019787', '', 'ciprian-popescu-dual-motors.jpg'],
    ['Daniela Popescu', 'Director Comercial', '0729019787', '', 'daniela-popescu-dual-motors.jpg'],
    ['George Surugiu', 'Director Vânzări', '0722354437', '', 'george-surugiu-dual-motors.jpg'],
    ['Alexandru Paraschivescu', 'Vânzări echipamente, accesorii și motociclete', '0722244016', '', 'Paraschivescu-Alexandru.jpg'],
    ['Dan Teodorescu', 'Vânzări piese, accesorii și motociclete', '0720004737', '', 'Dan-Teodorescu.jpg'],
    ['Mădălin Gâzdaru', 'Recepție service', '0724371365', '', 'Madalin-Gazdaru.jpg'],
    ['Petrică Marian', 'Mecanic', '0724371365', '', 'Petrica-Marian.jpg'],
    ['Pleșea Marian', 'Mecanic', '0724371365', '', 'Plesea-Marian.jpg'],
    ['Gabriel Teodorescu', 'Mecanic', '0724371365', '', 'Teodorescu-Gabriel.jpg'],
    ['Constanța Istrate', 'Facturare / Contabilitate', '0729019787', '', 'Constanta-Istrate.jpg'],
    ['Simona Oprea', 'Facturare / Contabilitate', '0729019787', '', 'simona-oprea.jpg'],
];
if ((int) $pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn() === 0) {
    $st = $pdo->prepare('INSERT INTO team_members (name, role, phone, email, photo, position, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    foreach ($team as $i => $m) {
        $st->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $i]);
    }
    echo count($team) . " team members seeded\n";
}

// --- History timeline -------------------------------------------------------
$specialized = <<<HTML
<p>Specialized înseamnă biciclete făcute de rideri pentru rideri, începând din 1974, californienii semnând – printre altele – și actul de naștere al primului MTB produs în serie, este vorba desigur despre Stumpjumper.</p>
<p>Cum și Dual Motors este un business început de rideri pentru rideri și pentru că suntem pasionați de tot ceea ce înseamnă sport pe două roți, este plăcerea noastră să anunțăm că din acest sezon am devenit dealeri și pentru legendara marcă de biciclete Specialized. Diversele discipline ale motociclismului s-au împăcat dintotdeauna bine cu ciclismul, mulți piloți moto folosindu-se de antrenamentele pe bicicletă pentru a-și menține condiția fizică, a se relaxa sau chiar și pentru a-și menține și exersa reflexele, știut fiind faptul că motocross-ul și downhill-ul, prin solicitările extreme pe care le implică, nu sunt de fapt atât de diferite.</p>
<p>Grație caracterului lor performant și inovativ, bicicletele Specialized sunt preferate de piloții moto din toată lumea. Gautier Paulin, care a fost alături de Yamaha în sezonul 2019 al mondialului de motocross, este acum unul dintre ambasadorii Specialized, el bucurându-se de gravel bike-ul electric Creo SL EVO, despre care spune că e incredibil de rapid și de distractiv. Tot o părere grozavă au despre MTB-urile lor electrice de la Specialized și riderii Ciprian Popescu, Adrian Răduță, Julian Răduță și Arthur Tataru, cu toții având deja numeroase aventuri la activ alături de aceste biciclete.</p>
<p>„Ne-am gândit să oferim și altor piloți moto și, desigur, publicului larg, oportunitatea de a veni în contact cu aceste biciclete de top, desigur, alături de la fel de performantele echipamente de la Specialized", spune Ciprian Popescu, manager Dual Motors.</p>
<p>Orice pasionat(ă) de ciclism a auzit de modele precum Stumpjumper, produs în continuare în variante full-suspension și hard tail, de Epic, al doilea MTB din istorie produs într-o versiune cu cadru din carbon, sau de cursierele Roubaix! Și pentru că istoria Specialized este o istorie dedicată inovației, din 2009 – anul în care a fost lansată prima bicicletă electrică – brandul este devotat ideii de a dezvolta biciclete electrice care să imprime riderului un feeling identic cu o bicicletă clasică, desigur cu excepția puterii.</p>
<p>Indiferent pe ce bicicletă îți dorești să pedalezi, de la BMX la MTB, de la un cruiser urban la cea mai aprigă cursieră, Specialized are o variantă pentru tine, care nu doar că excelează în ceea ce privește factorul cool, dar te va surprinde și cu soluțiile tehnice pe care le integrează!</p>
HTML;
$showroom2020 = <<<HTML
<p>Joi, 18 iunie, începând cu orele 16:00, echipa Dual Motors anunță un eveniment special de lansare a noii variante de showroom, aliniată celor mai noi standarde europene Yamaha. Dual Motors este primul dealer Yamaha din România care a adoptat noua identitate vizuală Yamaha, un concept unitar pentru Europa. Showroom-ul de 600 metri pătrați care a devenit atât de familiar pasionaților și pasionatelor fenomenului moto din București și nu doar, a fost reamenajat după un proiect executat de către un arhitect Yamaha Motor Europe, bucurându-se acum de un look ultra-modern, organizat pe „insule" distincte, care surprind esența mărcii. Iar look-ul extrem de atractiv este completat de o funcționalitate pe măsură.</p>
<p>Mai exact, fanii mărcii Yamaha, actuali sau viitori, sunt încă de la intrarea în showroom întâmpinați de insula „Core", care nu este doar o anticameră în universul Casei din Iwata, ci și un spațiu în care cei care doresc să își cumpere o motocicletă Yamaha o pot configura pe un monitor special, în cadrul unei discuții tihnite cu un reprezentant de vânzări al echipei Dual Motors. Tot în această zonă se află și un spațiu de ospitalitate pentru client, pentru ca mai departe să se poată „studia" un număr consistent de motociclete Yamaha, organizate în trei insule, „Race", „Feel" și „Move".</p>
<p>Noutățile nu se opresc însă aici. Și service-ul Dual Motors s-a bucurat de un „upgrade", numărul de posturi de lucru crescând de la 2 la 4, fiecare cu echiparea specifică, SDV-istică, elevator, mobilier. „Investiția s-a ridicat la peste 50.000 de euro, lucrările mergând până la detalii precum linoleum dedicat fiecărei insule, renovări în culorile specifice Yamaha și spoturi luminoase, amenajare vitrine, și nu ne vom opri aici, vom continua să amenajăm showroom-ul Dual Motors în conformitate cu identitatea brandurilor pentru care suntem importatori: Arai, Dainese, Putoline, Lazer", spune managerul Dual Motors, Ciprian Popescu.</p>
HTML;
$showroom2008 = <<<HTML
<p>Nu putem spune că mutarea în noul showroom de 600 de metri pătrați din Șoseaua Pipera numărul 48 s-a derulat în cel mai potrivit moment. Din contră, a coincis cu „ochiul crizei", momentul în care multe afaceri s-au închis. „Dacă ar fi fost să dau ascultare cifrelor seci și nu pasiunii pentru motociclism, și noi ar fi trebuit să renunțăm în acel moment, dar am căutat soluții", spune Ciprian Popescu. „Felul în care se prezenta piața moto în 2007 – 2008 impunea această mutare, noi dorindu-ne să ne prezentăm cât mai bine în fața clienților: un showroom mai atractiv, cu mai mult spațiu, cu un service; din păcate, în momentul în care am finalizat pregătirile – inițial aici a fost o hală folosită în anii '40 la construcția de avioane și lăsată în starea de atunci – a început criza. La 600 de metri pătrați, showroom-ul era prea mare pentru necesitățile noastre și l-am împărțit cu un business de mașini americane, care din nou, datorită schimbărilor economice, nu a rezistat", explică pe șleau managerul Dual Motors.</p>
HTML;
$sport2007 = <<<HTML
<p>Ciprian Popescu este de părere că Dual Motors putea evolua chiar și mai bine, o parte semnificativă a profiturilor obținute în urma activității firmei fiind investită în organizarea de competiții moto, dintre care multe au fost o premieră pentru România.</p>
<p>Iar din acest punct de vedere se poate spune că anii scurși până în 2003 au fost o perioadă de acumulări: „Fiind foarte pasionat de motocross, cum circulam mult în Europa, având de vizitat clienți din domeniul industriei chimice, eram mereu atent să văd dacă în respectiva zonă se desfășurau competiții de motocross și astfel am asistat la multe etape de europene sau mondiale. Încercam să învăț, să îmi iau notițe, simțeam că e cazul să se întâmple ceva asemănător și în România, deoarece la noi cursele erau slab organizate".</p>
<p>Acel „ceva" a însemnat în 2002 o etapă din Campionatul European de Motocross, o etapă de Campionat Mondial de Motocross cu Ataș în 2003 și, tot în același an, o etapă de Campionat Mondial de Supermoto, ultimele două evenimente primind calificativul „Excellent Events" din partea Federației Internaționale de Motociclism și a promoterului Youthstream. Tot de această perioadă se leagă și întâlnirea cu familia Răduță, apariția circuitului Gorgota – Ciolpani, și implicit deschiderea unui nou capitol în motocrosul românesc, care a culminat cu Dementor KTM Motocross Cup.</p>
HTML;
$showroom2003 = <<<HTML
<p>După ce a alergat la curse de motocross în anii '80, după Revoluția din 1989, Ciprian Popescu a hotărât să revină asupra acestei pasiuni, reluând o activitate competițională care continuă și azi. „Pe la curse multă lume mă întreba ce să își cumpere, îmi cerea sfaturi și, având deja firma Dual Tours, care la acel moment efectua transporturi rutiere, mai vindeam diverse produse necesare motocrosului. De aceea, am decis să încep un business cum se cuvine, într-un spațiu adecvat", își aduce aminte Ciprian.</p>
<p>Lucrurile s-au legat: „deoarece alergasem ani buni pe Yamaha și mă îndrăgostisem de această marcă, mi-a fost ușor să ajung la Yamaha România, unde în acea vreme lucra ca director Tiberiu Troia, o altă bună cunoștință din lumea curselor. Am devenit dealer Yamaha, punând la punct un showroom cu câteva motociclete, echipamente și accesorii", rememorează Ciprian.</p>
HTML;

$history = [
    [2021, 'Dual Motors devine dealer Specialized', $specialized, 0,
        ['specialized1.jpg', 'specialized2.jpg', 'specialized3.jpg', 'specialized4.jpg', 'specialized5.jpg']],
    [2020, 'Upgrade la standardele europene Yamaha pentru showroom', $showroom2020, 0,
        ['showroom-dual-motors-bucuresti-2020-2.jpg', 'showroom-dual-motors-bucuresti-2020-3.jpg',
         'showroom-dual-motors-bucuresti-2020-4.jpg', 'showroom-dual-motors-bucuresti-2020-5.jpg',
         'showroom-dual-motors-bucuresti-2020-6.jpg', 'showroom-dual-motors-bucuresti-2020-7.jpg',
         'showroom-dual-motors-bucuresti-2020-8.jpg', 'showroom-dual-motors-bucuresti-2020-9.jpg']],
    [2016, 'Lansare BikerShop by Dual Motors', '<p>Lansăm BikerShop, magazinul online cu mii de produse pentru tine şi motocicleta ta.</p>', 0,
        ['bikershop-site-motociclete.jpg']],
    [2016, '15 ani de circuit motocros Ciolpani', '', 1,
        ['2016/P1670599.jpg', '2016/P1670701.jpg', '2016/P1670721.jpg']],
    [2014, 'Echipare BGS cu motociclete Yamaha', '', 0,
        ['2014/bgs1.jpg', '2014/bgs2.jpg']],
    [2008, 'Un nou showroom și o investiție de 180.000 euro', $showroom2008, 0,
        ['dual-showroom-pipera-2015.jpg']],
    [2007, 'Implicare totală în sport', $sport2007, 0,
        ['dual-supermoto.jpg', 'dual-supermoto1.jpg']],
    [2003, 'Showroom moto Yamaha', $showroom2003, 0,
        ['showroom-dual-motors-2003.jpg', 'dual-primul-showroom.jpg']],
];
if ((int) $pdo->query('SELECT COUNT(*) FROM history_entries')->fetchColumn() === 0) {
    $ins = $pdo->prepare('INSERT INTO history_entries (`year`, title, body_html, position, is_active) VALUES (?, ?, ?, ?, 1)');
    $img = $pdo->prepare('INSERT INTO history_images (entry_id, filename, position) VALUES (?, ?, ?)');
    foreach ($history as $h) {
        $ins->execute([$h[0], $h[1], $h[2], $h[3]]);
        $eid = (int) $pdo->lastInsertId();
        foreach ($h[4] as $j => $f) {
            $img->execute([$eid, $f, $j]);
        }
    }
    echo count($history) . " history entries seeded\n";
}

echo "seed_about: done.\n";
