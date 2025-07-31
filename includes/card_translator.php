<?php
// Kartentext-Übersetzungsfunktionen
class CardTranslator {
    
    // Deutsche Übersetzungen für häufige Begriffe
    private static $translations = [
        // Kartentypen
        'Creature' => 'Kreatur',
        'Instant' => 'Spontanzauber',
        'Sorcery' => 'Hexerei',
        'Enchantment' => 'Verzauberung',
        'Artifact' => 'Artefakt',
        'Planeswalker' => 'Planeswalker',
        'Land' => 'Land',
        'Legendary' => 'Legendär',
        'Basic' => 'Standard',
        'Token' => 'Spielstein',
        'Aura' => 'Aura',
        'Equipment' => 'Ausrüstung',
        'Vehicle' => 'Fahrzeug',
        
        // Untertypen
        'Human' => 'Mensch',
        'Soldier' => 'Soldat',
        'Warrior' => 'Krieger',
        'Knight' => 'Ritter',
        'Angel' => 'Engel',
        'Dragon' => 'Drache',
        'Beast' => 'Bestie',
        'Goblin' => 'Goblin',
        'Elf' => 'Elf',
        'Wizard' => 'Zauberer',
        'Spirit' => 'Geist',
        'Zombie' => 'Zombie',
        'Vampire' => 'Vampir',
        'Demon' => 'Dämon',
        'Bird' => 'Vogel',
        'Cat' => 'Katze',
        'Wolf' => 'Wolf',
        'Bear' => 'Bär',
        'Snake' => 'Schlange',
        'Spider' => 'Spinne',
        'Insect' => 'Insekt',
        'Fish' => 'Fisch',
        'Merfolk' => 'Tritonen',
        'Elemental' => 'Elementarwesen',
        'Giant' => 'Riese',
        'Dwarf' => 'Zwerg',
        
        // Fähigkeiten
        'Flying' => 'Flugfähigkeit',
        'Trample' => 'Verursacht Trampelschaden',
        'First Strike' => 'Erstschlag',
        'Double Strike' => 'Doppelschlag',
        'Deathtouch' => 'Todesberührung',
        'Lifelink' => 'Lebensverknüpfung',
        'Vigilance' => 'Wachsamkeit',
        'Haste' => 'Eile',
        'Reach' => 'Reichweite',
        'Hexproof' => 'Fluchsicherheit',
        'Shroud' => 'Tarnung',
        'Indestructible' => 'Unzerstörbar',
        'Flash' => 'Aufblitzen',
        'Defender' => 'Verteidiger',
        'Menace' => 'Bedrohlich',
        'Prowess' => 'Kampfkunst',
        'Scry' => 'Hellsicht',
        'Surveil' => 'Erkunden',
        'Convoke' => 'Einberufung',
        'Delve' => 'Wühlen',
        'Emerge' => 'Hervorbringen',
        'Madness' => 'Wahnsinn',
        'Flashback' => 'Rückblende',
        'Kicker' => 'Bonuskosten',
        'Morph' => 'Morph',
        'Cycling' => 'Kreislauf',
        'Echo' => 'Echo',
        'Buyback' => 'Rückkauf',
        'Storm' => 'Sturm',
        'Suspend' => 'Aussetzen',
        'Split second' => 'Bruchteil einer Sekunde',
        'Cascade' => 'Kaskade',
        'Rebound' => 'Zurückprallen',
        'Undying' => 'Unsterblich',
        'Persist' => 'Beharren',
        'Wither' => 'Verkümmern',
        'Infect' => 'Infizieren',
        'Intimidate' => 'Einschüchtern',
        'Landfall' => 'Landung',
        'Metalcraft' => 'Metallkunst',
        'Threshold' => 'Schwellenwert',
        'Hellbent' => 'Höllenbund',
        'Bloodthirst' => 'Blutdurst',
        'Morbid' => 'Morbid',
        'Fateful hour' => 'Verhängnisvolle Stunde',
        'Miracle' => 'Wunder',
        'Overload' => 'Überlastung',
        'Populate' => 'Bevölkern',
        'Detain' => 'Festhalten',
        'Unleash' => 'Entfesseln',
        'Cipher' => 'Chiffre',
        'Evolve' => 'Entwickeln',
        'Extort' => 'Erpressen',
        'Fuse' => 'Verschmelzen',
        'Bestow' => 'Verleihen',
        'Heroic' => 'Heldenhaft',
        'Monstrosity' => 'Monstrosität',
        'Devotion' => 'Hingabe',
        'Inspired' => 'Inspiriert',
        'Constellation' => 'Konstellation',
        'Ferocious' => 'Wild',
        'Dash' => 'Ansturm',
        'Renown' => 'Ruhm',
        'Awaken' => 'Erwecken',
        'Devoid' => 'Farblos',
        'Ingest' => 'Verschlingen',
        'Myriad' => 'Myriad',
        'Surge' => 'Aufwallung',
        'Cohort' => 'Kohorte',
        'Support' => 'Unterstützen',
        'Skulk' => 'Schleichen',
        'Investigate' => 'Ermitteln',
        'Escalate' => 'Eskalieren',
        'Melee' => 'Handgemenge',
        'Crew' => 'Bemannen',
        'Fabricate' => 'Herstellen',
        'Partner' => 'Partner',
        'Undaunted' => 'Furchtlos',
        'Improvise' => 'Improvisieren',
        'Revolt' => 'Revolte',
        'Expertise' => 'Sachkenntnis',
        'Embalm' => 'Einbalsamieren',
        'Eternalize' => 'Verewigen',
        'Aftermath' => 'Folge',
        'Afflict' => 'Heimsuchen',
        'Enrage' => 'Erzürnen',
        'Treasure' => 'Schatz',
        'Ascend' => 'Aufsteigen',
        'Undergrowth' => 'Unterholz',
        'Jump-start' => 'Starthilfe',
        'Adapt' => 'Anpassen',
        'Addendum' => 'Zusatz',
        'Spectacle' => 'Spektakel',
        'Riot' => 'Aufruhr',
        'Amass' => 'Anhäufen',
        'Proliferate' => 'Verbreiten',
        'War' => 'Krieg',
        'Adventure' => 'Abenteuer',
        'Food' => 'Nahrung',
        'Adamant' => 'Unnachgiebig',
        'Escape' => 'Entkommen',
        'Companion' => 'Gefährte',
        'Keyword' => 'Schlüsselwort',
        'Ability' => 'Fähigkeit',
        
        // Aktionen und Verben
        'Draw a card' => 'Ziehe eine Karte',
        'Draw cards' => 'Ziehe Karten',
        'Search your library' => 'Durchsuche deine Bibliothek',
        'Shuffle your library' => 'Mische deine Bibliothek',
        'Put into your hand' => 'Nimm auf deine Hand',
        'Put on top of your library' => 'Lege oben auf deine Bibliothek',
        'Put on the bottom of your library' => 'Lege unter deine Bibliothek',
        'Put onto the battlefield' => 'Bringe ins Spiel',
        'Exile' => 'Schicke ins Exil',
        'Exile target' => 'Schicke ins Exil',
        'Destroy target' => 'Zerstöre',
        'Destroy all' => 'Zerstöre alle',
        'Deal damage' => 'Füge Schaden zu',
        'Deal X damage' => 'Füge X Schaden zu',
        'Gain life' => 'Erhalte Lebenspunkte',
        'Lose life' => 'Verliere Lebenspunkte',
        'Counter target spell' => 'Neutralisiere einen Zauberspruch deiner Wahl',
        'Return to hand' => 'Bringe auf die Hand zurück',
        'Return to your hand' => 'Bringe auf deine Hand zurück',
        'Return to its owner\'s hand' => 'Bringe auf die Hand seines Besitzers zurück',
        'Discard' => 'Lege ab',
        'Discard a card' => 'Lege eine Karte ab',
        'Sacrifice' => 'Opfere',
        'Sacrifice a' => 'Opfere eine',
        'Sacrifice target' => 'Opfere',
        'Tap' => 'Tappe',
        'Untap' => 'Enttappe',
        'Look at' => 'Schaue dir an',
        'Reveal' => 'Decke auf',
        'Choose' => 'Bestimme',
        'Choose one' => 'Bestimme eines',
        'Choose two' => 'Bestimme zwei',
        'Search' => 'Durchsuche',
        'Shuffle' => 'Mische',
        'Mill' => 'Mülle',
        'Create' => 'Erzeuge',
        'Copy' => 'Kopiere',
        'Transform' => 'Verwandle',
        'Flip' => 'Wende um',
        'Attach' => 'Lege an',
        'Detach' => 'Löse',
        'Equip' => 'Rüste aus',
        'Activate' => 'Aktiviere',
        'Cast' => 'Wirke',
        'Play' => 'Spiele',
        'Pay' => 'Bezahle',
        'Add' => 'Erzeuge',
        'Spend' => 'Gib aus',
        'Prevent' => 'Verhindere',
        'Replace' => 'Ersetze',
        'Double' => 'Verdopple',
        'Halve' => 'Halbiere',
        'Skip' => 'Überspringe',
        'End' => 'Beende',
        'Begin' => 'Beginne',
        'Take an extra turn' => 'Mache einen zusätzlichen Zug',
        'Take control' => 'Übernimm die Kontrolle',
        'Gain control' => 'Übernimm die Kontrolle',
        'Exchange' => 'Tausche',
        'Swap' => 'Vertausche',
        
        // Häufige Wörter und Phrasen
        'target' => 'deiner Wahl',
        'any target' => 'ein beliebiges Ziel',
        'another target' => 'ein anderes Ziel',
        'you control' => 'die du kontrollierst',
        'you own' => 'die du besitzt',
        'you don\'t control' => 'die du nicht kontrollierst',
        'an opponent controls' => 'die ein Gegner kontrolliert',
        'enters the battlefield' => 'ins Spiel kommt',
        'leaves the battlefield' => 'das Spiel verlässt',
        'dies' => 'stirbt',
        'is destroyed' => 'zerstört wird',
        'is exiled' => 'ins Exil geschickt wird',
        'attacks' => 'angreift',
        'blocks' => 'blockt',
        'becomes blocked' => 'geblockt wird',
        'deals damage' => 'Schaden zufügt',
        'takes damage' => 'Schaden erleidet',
        'beginning' => 'Beginn',
        'end' => 'Ende',
        'upkeep' => 'Versorgungssegment',
        'draw step' => 'Ziehsegment',
        'main phase' => 'Hauptphase',
        'combat' => 'Kampf',
        'declare attackers' => 'Angreifer bestimmen',
        'declare blockers' => 'Blocker bestimmen',
        'combat damage' => 'Kampfschaden',
        'end of combat' => 'Ende des Kampfes',
        'cleanup step' => 'Aufräumsegment',
        'turn' => 'Zug',
        'your turn' => 'dein Zug',
        'each turn' => 'jeden Zug',
        'this turn' => 'in diesem Zug',
        'next turn' => 'nächsten Zug',
        'mana' => 'Mana',
        'mana cost' => 'Manakosten',
        'converted mana cost' => 'umgewandelte Manakosten',
        'mana value' => 'Manabetrag',
        'cost' => 'Kosten',
        'additional cost' => 'zusätzliche Kosten',
        'alternative cost' => 'alternative Kosten',
        'ability' => 'Fähigkeit',
        'activated ability' => 'aktivierte Fähigkeit',
        'triggered ability' => 'ausgelöste Fähigkeit',
        'static ability' => 'statische Fähigkeit',
        'spell' => 'Zauberspruch',
        'permanent' => 'bleibende Karte',
        'nonland permanent' => 'bleibende Nichtland-Karte',
        'creature spell' => 'Kreaturenzauber',
        'noncreature spell' => 'Nichtkreatuerzauber',
        'graveyard' => 'Friedhof',
        'your graveyard' => 'deinen Friedhof',
        'hand' => 'Hand',
        'your hand' => 'deine Hand',
        'library' => 'Bibliothek',
        'your library' => 'deine Bibliothek',
        'battlefield' => 'Schlachtfeld',
        'the battlefield' => 'das Schlachtfeld',
        'exile' => 'Exil',
        'command zone' => 'Kommandozone',
        'stack' => 'Stapel',
        'the stack' => 'den Stapel',
        
        // Mengen und Zahlen
        'one' => 'eine',
        'two' => 'zwei',
        'three' => 'drei',
        'four' => 'vier',
        'five' => 'fünf',
        'six' => 'sechs',
        'seven' => 'sieben',
        'eight' => 'acht',
        'nine' => 'neun',
        'ten' => 'zehn',
        'all' => 'alle',
        'each' => 'jede',
        'any' => 'beliebige',
        'another' => 'eine andere',
        'other' => 'andere',
        'up to' => 'bis zu',
        'exactly' => 'genau',
        'at least' => 'mindestens',
        'at most' => 'höchstens',
        'or more' => 'oder mehr',
        'or less' => 'oder weniger',
        
        // Farben
        'white' => 'weiß',
        'blue' => 'blau',
        'black' => 'schwarz',
        'red' => 'rot',
        'green' => 'grün',
        'colorless' => 'farblos',
        'multicolored' => 'mehrfarbig',
        'monocolored' => 'einfarbig',
        
        // Zeitangaben und Bedingungen
        'When' => 'Wenn',
        'Whenever' => 'Immer wenn',
        'As long as' => 'Solange',
        'If' => 'Falls',
        'Unless' => 'Es sei denn',
        'Until' => 'Bis',
        'During' => 'Während',
        'At' => 'Zu',
        'Before' => 'Vor',
        'After' => 'Nach',
        'Instead' => 'Stattdessen',
        'Rather than' => 'Anstatt',
        'May' => 'kann',
        'Must' => 'muss',
        'Can\'t' => 'kann nicht',
        'Don\'t' => 'nicht',
        
        // Häufige Adjektive
        'tapped' => 'getappt',
        'untapped' => 'ungetappt',
        'attacking' => 'angreifend',
        'blocking' => 'blockend',
        'unblocked' => 'ungeblockt',
        'blocked' => 'geblockt',
        'equipped' => 'ausgerüstet',
        'enchanted' => 'verzaubert',
        'attached' => 'angelegt',
        'face up' => 'offen',
        'face down' => 'verdeckt',
        'token' => 'Spielstein',
        'copy' => 'Kopie',
        'original' => 'Original',
        'additional' => 'zusätzliche',
        'extra' => 'zusätzliche',
        'first' => 'erste',
        'last' => 'letzte',
        'next' => 'nächste',
        'previous' => 'vorherige',
        'new' => 'neue',
        'old' => 'alte',
        'random' => 'zufällige',
        'chosen' => 'bestimmte',
        'selected' => 'ausgewählte',
        'named' => 'benannte',
        
        // Häufige Substantive
        'player' => 'Spieler',
        'opponent' => 'Gegner',
        'controller' => 'Beherrscher',
        'owner' => 'Besitzer',
        'source' => 'Quelle',
        'effect' => 'Effekt',
        'damage' => 'Schaden',
        'life' => 'Lebenspunkte',
        'power' => 'Stärke',
        'toughness' => 'Widerstandskraft',
        'loyalty' => 'Loyalität',
        'counter' => 'Marke',
        'counters' => 'Marken',
        '+1/+1 counter' => '+1/+1-Marke',
        '-1/-1 counter' => '-1/-1-Marke',
        'charge counter' => 'Ladungsmarke',
        'time counter' => 'Zeitmarke',
        'poison counter' => 'Giftmarke',
        'experience counter' => 'Erfahrungsmarke',
        'energy counter' => 'Energiemarke',
        'card' => 'Karte',
        'cards' => 'Karten',
        'name' => 'Name',
        'type' => 'Typ',
        'subtype' => 'Untertyp',
        'supertype' => 'Obertyp',
        'color' => 'Farbe',
        'colors' => 'Farben',
        'identity' => 'Identität',
        'symbol' => 'Symbol',
        'text' => 'Text',
        'rules text' => 'Regeltext',
        'flavor text' => 'Hintergrundtext',
        'reminder text' => 'Erinnerungstext'
    ];
    
    /**
     * Übersetzt Kartentext von Englisch ins Deutsche
     */
    public static function translateCardText($englishText, $targetLanguage = 'de') {
        if ($targetLanguage !== 'de' || empty($englishText)) {
            return $englishText;
        }
        
        $translatedText = $englishText;
        
        // Sortiere Übersetzungen nach Länge (längste zuerst) für bessere Präzision
        $sortedTranslations = self::$translations;
        uksort($sortedTranslations, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        // Durchlaufe alle Übersetzungen und ersetze sie
        foreach ($sortedTranslations as $english => $german) {
            // Verwende Wortgrenzen für präzisere Übersetzungen
            $pattern = '/\b' . preg_quote($english, '/') . '\b/i';
            $translatedText = preg_replace($pattern, $german, $translatedText);
        }
        
        // Spezielle Behandlung für häufige Phrasen mit Kontext
        $phraseReplacements = [
            '/(\d+)\s+damage\b/i' => '$1 Schaden',
            '/\bdeal\s+(\d+)\s+damage\b/i' => 'füge $1 Schaden zu',
            '/\bgain\s+(\d+)\s+life\b/i' => 'erhalte $1 Lebenspunkte',
            '/\blose\s+(\d+)\s+life\b/i' => 'verliere $1 Lebenspunkte',
            '/\bdraw\s+(\d+)\s+cards?\b/i' => 'ziehe $1 Karte(n)',
            '/\bsacrifice\s+a\s+(\w+)\b/i' => 'opfere eine $1',
            '/\btap\s+target\s+(\w+)\b/i' => 'tappe eine $1 deiner Wahl',
            '/\bdestroy\s+target\s+(\w+)\b/i' => 'zerstöre eine $1 deiner Wahl',
            '/\breturn\s+target\s+(\w+)\s+to\s+its\s+owner\'?s\s+hand\b/i' => 'bringe eine $1 deiner Wahl auf die Hand ihres Besitzers zurück',
            '/\bput\s+a\s+\+1\/\+1\s+counter\s+on\s+target\s+(\w+)\b/i' => 'lege eine +1/+1-Marke auf eine $1 deiner Wahl',
            '/\bput\s+(\d+)\s+\+1\/\+1\s+counters?\s+on\s+target\s+(\w+)\b/i' => 'lege $1 +1/+1-Marke(n) auf eine $2 deiner Wahl',
            '/\bcreate\s+a\s+(\d+)\/(\d+)\s+(\w+)\s+creature\s+token\b/i' => 'erzeuge einen $1/$2 $3-Kreaturenspielstein',
            '/\bcreate\s+(\d+)\s+(\d+)\/(\d+)\s+(\w+)\s+creature\s+tokens?\b/i' => 'erzeuge $1 $2/$3 $4-Kreaturenspielsteine',
            '/\bwhen\s+(.+?)\s+enters\s+the\s+battlefield\b/i' => 'wenn $1 ins Spiel kommt',
            '/\bwhenever\s+(.+?)\s+attacks\b/i' => 'immer wenn $1 angreift',
            '/\bwhenever\s+(.+?)\s+dies\b/i' => 'immer wenn $1 stirbt',
        ];
        
        foreach ($phraseReplacements as $pattern => $replacement) {
            $translatedText = preg_replace($pattern, $replacement, $translatedText);
        }
        
        return $translatedText;
    }
    
    /**
     * Übersetzt Kartenname falls verfügbar
     */
    public static function translateCardName($englishName, $targetLanguage = 'de') {
        if ($targetLanguage !== 'de') {
            return $englishName;
        }
        
        // Hier könnten wir eine Datenbank mit deutschen Kartennamen haben
        // Für jetzt geben wir den englischen Namen zurück
        return $englishName;
    }
    
    /**
     * Übersetzt Kartentyp
     */
    public static function translateCardType($englishType, $targetLanguage = 'de') {
        if ($targetLanguage !== 'de') {
            return $englishType;
        }
        
        $translatedType = $englishType;
        
        // Übersetze Kartentypen
        foreach (self::$translations as $english => $german) {
            if (stripos($englishType, $english) !== false) {
                $translatedType = str_ireplace($english, $german, $translatedType);
            }
        }
        
        return $translatedType;
    }
    
    /**
     * Holt Benutzerspracheinstellung
     */
    public static function getUserLanguage($pdo, $user_id) {
        try {
            $stmt = $pdo->prepare("SELECT language_preference FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetchColumn();
            return $result ?: 'en';
        } catch (Exception $e) {
            return 'en';
        }
    }
    
    /**
     * Rendert Übersetzungsbutton für AJAX-Aufrufe
     */
    public static function renderTranslateButton($cardId, $currentLang = 'en') {
        $nextLang = $currentLang === 'en' ? 'de' : 'en';
        $buttonText = $currentLang === 'en' ? '🇩🇪 Deutsch' : '🇺🇸 English';
        
        return '<button class="translate-btn" data-card-id="' . $cardId . '" data-target-lang="' . $nextLang . '">' . $buttonText . '</button>';
    }
    
    /**
     * Erweiterte Übersetzung mit Kontext
     */
    public static function translateWithContext($text, $context = 'general', $targetLanguage = 'de') {
        if ($targetLanguage !== 'de') {
            return $text;
        }
        
        // Kontext-spezifische Übersetzungen
        $contextTranslations = [
            'abilities' => [
                'Tap:' => 'Tappe:',
                'Untap:' => 'Enttappe:',
                'When ~ enters the battlefield' => 'Wenn ~ ins Spiel kommt',
                'At the beginning of your turn' => 'Zu Beginn deines Zuges',
                'Whenever' => 'Immer wenn',
                'As long as' => 'Solange',
                'If' => 'Falls'
            ],
            'costs' => [
                'Pay' => 'Bezahle',
                'Sacrifice' => 'Opfere',
                'Discard' => 'Lege ab',
                'Tap' => 'Tappe'
            ]
        ];
        
        $result = self::translateCardText($text, $targetLanguage);
        
        // Kontext-spezifische Übersetzungen anwenden
        if (isset($contextTranslations[$context])) {
            foreach ($contextTranslations[$context] as $english => $german) {
                $result = str_ireplace($english, $german, $result);
            }
        }
        
        return $result;
    }
}
?>
