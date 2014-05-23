#!/usr/bin/php
<?php
//include __main__;
//  

include_once 'classes/map.php';
include_once 'classes/moveReg.php';
include_once 'classes/strat.php';

$use_map_specific_strategy = "small_earth";
$run = true;
$map = new CMap();
$mreg = new CRegister($map);
$strat = new CStrat($map, $mreg);
$log = false;
$round = 0;
$line = 0;
$loadgame = array();
$loading = 0;
$verbose = 0;
$north_africa_block = 0;

if (count($argv) > 1){
    if($argv[1] == "log"){
        $log = "logs/" . time() . ".txt";
        $f = fopen( $log , "w");
        fclose($f);
    }
    if(isset($argv[2])){
        $gametoload = "logs/" . $argv[2] . ".txt";
        $game = fopen($gametoload, "r");
        while ($line = fgets($game)){
            $linetype = explode(" ", $line);
            if ((count($linetype) < 1) || ($linetype[0] == "Round") || ($linetype[0] == "Maximum")
                 || ($linetype[0] == "player1") || ($linetype[0] == "player2") || ($linetype[0] == "No")){
                continue;
            }else{
                $loadgame[] = $line;                
            }
        }
    }
    if(isset($argv[3])){
        if($argv[3] == "xtra"){
            $verbose = true;
        }
    }
}

while($run){
    if($loading < count($loadgame)){
        $instruction = trim($loadgame[$loading]);
        $loading++;
    }else{        
        $instruction = trim(fgets(STDIN));
    }
    toLog($instruction);
    $response = handleInput($instruction);
    toLog($response);
    if ($instruction == "x"){
        $run = false;
    }
    if ($response != ""){
        fwrite(STDOUT, $response . "\n");
    }
}

function handleInput($inputStr){
    global $map, $mreg, $round;
    $selector_string = substr($inputStr, 0, 11);
    $outputStr = "";
    switch ($selector_string){
        case "x":
            break;
        case "setup_map s":
            readBonuses($inputStr);
            break;
        case "setup_map r":
            readRegions($inputStr);
            break;
        case "setup_map n":
            readConnections($inputStr);
            break;
        case "pick_starti":
            $outputStr = pickStarts($inputStr);
            break;
        case "settings yo":
            $map->player_one = substr($inputStr, strlen("settings your_bot "));
            break;
        case "settings op":
            $map->player_two = substr($inputStr, strlen("settings opponent_bot "));
            break;
        case "settings st":
            $map->income_one = substr($inputStr, strlen("settings starting_armies "));
            $map->armies_left_deploy = $map->income_one;
            if ($map->income_two == ""){
                $map->income_two = $map->income_one;
            }
            break;
        case "update_map ":
            updateMap($inputStr);
            break;
        case "opponent_mo":
            readOppMoves($inputStr);
            $mreg->regMoves($inputStr, false, $round - 1);
            break;
        case "go place_ar":
            thinkMoves();
            $outputStr = moveDeploy();
            $mreg->regMoves($outputStr, true, $round);
            break;
        case "go attack/t":
            $outputStr = moveAttack();
            $mreg->regMoves($outputStr, true, $round);
            break;
        default :
            break;
    }
    return $outputStr;
}

function readBonuses($inputStr){
    // example query is: "setup_map super_regions 1 2 2 5"
    global $map;
    $map->smallestbonus = 999;
    $map->biggestbonus = 0;
    $inputStr = explode(" ", substr($inputStr, strlen("setup_map super_regions ")));
    $income = 0;
    $id = 0;
    for($i = 0; $i < count($inputStr); $i++){
        if (!($i%2)){
            $id = $inputStr[$i];
        }else{
            $income = $inputStr[$i];
            $newbonus = new CBonus();
            $newbonus->income = $income;
            if($income < $map->smallestbonus){
                $map->smallestbonus = $income;
            }
            if($income > $map->biggestbonus){
                $map->biggestbonus = $income;
            }
            $newbonus->id = $id;
            $map->bonuses[$id] = $newbonus;
        }        
    }
    return "";
}

function readRegions($inputStr){
    // example query is: "setup_map regions 1 1 2 1 3 2 4 2 5 2"
    global $map;
    $inputStr = explode(" ", substr($inputStr, strlen("setup_map regions ")));
    $id = 0;
    $bonus = 0;
    for($i = 0; $i < count($inputStr); $i++){
        if (!($i%2)){
            $id = $inputStr[$i];
        }else{
            $bonus = $inputStr[$i];
            $newregion = new CRegion();
            $newregion->bonus = $bonus;
            $newregion->id = $id;
            $newregion->armies = 2;
            $map->bonuses[$bonus]->regions[$newregion->id] = $newregion;
            $map->regions[$newregion->id] = &$map->bonuses[$bonus]->regions[$newregion->id];
        }        
    }
    return "";
}

function readConnections($inputStr){
    // example query is: "setup_map neighbors 1 2,3,4 2 3 4 5"
    global $map;
    $inputStr = explode(" ", substr($inputStr, strlen("setup_map neighbors ")));
    $start = 0;
    $end = 0;
    for($i = 0; $i < count($inputStr); $i++){
        if (!($i%2)){
            $start = $inputStr[$i];
        }else{
            $end = explode(",", $inputStr[$i]);
            foreach ($end as &$endpos){
                $map->regions[$start]->connections[] = $endpos;
                $map->regions[$endpos]->connections[] = $start;
            }
        }        
    }
    return "";
}

function pickStarts($inputStr){
    // example query is: "pick_starting_regions 2000 1 2 3 4 5 6 7 8 9 10"
    global $map;
    $starts_raw = explode(" ", $inputStr);
    $starts = array_slice($starts_raw, 2);
    $isolationval = array_fill(1, count($map->bonuses), 0);
    foreach ($map->bonuses as $bonus){
        $val = 0;
        foreach ($bonus->regions as $region){
            if( 1 > count($map->bonuses_bordering_prov($region->id))){
                $val += 10;
            }
        }
        $isolationval[$bonus->id] = round($val/(count($bonus->regions)));
    }
    toLogX("isolation values: " . implode(",", $isolationval));
    $evaluated = array();
    foreach ($starts as $start){
        $bonus = $map->regions[$start]->bonus;
        $bonusval = $map->bonuses[$bonus]->income;
        $value = 1000 - ($bonusval*20);
        $value += count($map->regions[$start]->connections);
        $ady_bonuses = $map->bonuses_bordering_prov($start);
        $value += count($ady_bonuses);
        foreach ($ady_bonuses as $ady_bonus){
            if($map->bonuses[$ady_bonus]->income == $map->smallestbonus){
                $value += 3;
            }
        }
        $value += $isolationval[$bonus];
        
        $evaluated[$start] = $value;
        toLogX("start {$start} evaluated at {$value} points");
    }
    $orders = array();
    while(count($orders) < 6){        
        asort($evaluated);
        $key_array = array_keys($evaluated);
        $chosen = array_pop($key_array);
        array_pop($evaluated);
        $chosenbonus = $map->regions[$chosen]->bonus;
        $orders[] = $chosen;
        foreach ($evaluated as $key => $value){
            if($map->regions[$key]->bonus == $chosenbonus ){
                $value -= 5;
                $evaluated[$key] = $value;
            }
        }
    }
    $map->start_picks = $orders;
    return implode(" ", $orders);
}

function updateMap($inputStr){
    // example query is: "update_map 1 player1 2 2 player1 4 3 neutral 2 4 player2 5"
    global $round;
    global $map, $strat;
    $round++;
    toLog("\n*********************************************");
    toLog("*****************  ROUND $round  ************");
    toLog("*********************************************\n");
    // new round, enemy moves get archived
    $map->enemy_deploy[$round - 1] = $map->deploy_last;
    // default deploy stats are set to "no info"
    $map->deploy_last = array_fill(1, count($map->regions), -1);
    $map->sos_call = array_fill(1, count($map->regions), -1);
    $map->blocked_regions = array_fill(1, count($map->regions), -1);
    $map->enemy_last_regions = $map->enemy_regions;
    $map->my_last_regions = $map->my_regions;
    $inputparts = explode(" ", substr($inputStr, strlen("update_map ")));
    $my_new_regions = array();
    $map->bonuses_broken_last_turn = array();
    $map->threats_to_bonus = array();
    $map->armies_left_region = array();
    $map->smallest_disputed_bonus = 999;
    $id = 0; 
    $owner = 0; 
    $armies = 0;
    $visible_regions = array();
    for($i = 0; $i < count($inputparts); $i++){
        switch($i%3){
            case 0:
                $id = $inputparts[$i];
                break;
            case 1:
                $owner = $inputparts[$i];
                if ($owner == $map->player_one){
                    $my_new_regions[] = $id;
                }
                if ($owner == $map->player_two){
                    $map->enemy_regions[] = $id;
                }elseif(in_array($id, $map->enemy_regions)){
                    $pos = array_search($id, $map->enemy_regions);
                    unset($map->enemy_regions[$pos]);
                }
                break;
            case 2:
                $armies = $inputparts[$i];
                $map->regions[$id]->owner = $owner;
                $map->regions[$id]->armies = $armies;
                $visible_regions[] = $id;
                $map->armies_left_region[$id] = $armies;
                
                break;
        }
    }
    foreach($map->regions as $region){
        if(in_array($region->id, $visible_regions)){
            $region->visible = 1;
            if($region->owner == $map->player_two){
                // we see this region, so we can set its deploy to 0 and thus enable counting
                $map->deploy_last[$region->id] = 0;
            }
        }else{
            $region->armies = 1;
            $region->visible = 0;
            // neutral regions that havent been given by the engine could be enemies now
            if ($region->owner == "neutral"){
                $region->owner = "unknown";
            }
        }
    }
    // regions that i used to own but not anymore are enemy regions now
    $map->enemy_regions = array_diff($map->enemy_regions, $my_new_regions);
    $map->enemy_regions = array_unique(array_merge($map->enemy_regions, array_diff($map->my_regions, $my_new_regions)));
    foreach ($map->enemy_regions as $region_id){
        $map->regions[$region_id]->owner = $map->player_two;
    }
    $map->my_regions = $my_new_regions;
    
    $map->my_bonuses = array();
    $map->enemy_bonuses = array();
    $map->income_two = 5;
    foreach($map->bonuses as $bonus){
        $bonus->owner = $map->bonus_owner($bonus->id);
        if (($bonus->owner == "neutral") && ($bonus->income < $map->smallest_disputed_bonus)){
            $map->smallest_disputed_bonus = $bonus->income;
        }
        if (in_array($bonus->id, $map->guessed_bonuses)){
            if( ($bonus->owner == $map->player_one) || ($bonus->owner ==  "neutral") ) {
                $strat->remove_guessed_bonus($bonus->id);
            }else{
                $bonus->owner = $map->player_two;
            }
        }
        switch($bonus->owner){
            case $map->player_one:
                $map->my_bonuses[] = $bonus->id;
                break;
            case $map->player_two:
                $map->enemy_bonuses[] = $bonus->id;
                $map->income_two += $bonus->income;
                break;
        }
        toLog("bonus {$map->bonus_names[$bonus->id]} owned by $bonus->owner");
    }
    $map->armies_left_region = "";
    foreach ($map->my_regions as $regionid){
        $map->armies_left_region[$regionid] = $map->regions[$regionid]->armies;
    }
    global $verbose;
    if($verbose > 0){
        foreach ($map->regions as $region){
            $visible = $region->visible ? "visible" : "invisible";
            toLogX("region {$map->region_names[$region->id]} owned by {$region->owner} ($visible)");
        }
    }
    if($round == 1){
        $map->start_regions = $map->my_regions;
    }
}

function readOppMoves($inputStr){
    // example query is: opponent_moves player2 place_armies 14 2 player2 attack/transfer 5 7 5
    global $map;
    $inputArr = explode(" ", $inputStr);
    $counted_enemy_deploy = 0;
    $count = 0;
    $nextamount = -1;
    $nextlocation = -1;
    foreach ($inputArr as $entry){
        if ($count == $nextamount){
            if($map->deploy_last[$nextlocation] < 0){
                $map->deploy_last[$nextlocation] = 0;
            }
            $counted_enemy_deploy += $entry;
            $map->deploy_last[$nextlocation] += $entry;
        }elseif($count == $nextlocation){
            $nextlocation = $entry;
        }elseif ($entry == "place_armies"){
            $nextamount = $count + 2;
            $nextlocation = $count + 1;
        }
        $count++;
    }    
    return $inputStr;    
}

function thinkMoves(){      // priority/delay
    global $strat, $round, $use_map_specific_strategy;
    $strat->think($round);
    $state = $strat->get_state();
    
    if($state == 1){
        toLog("");
        toLog("opening move");
        $state = openingMove();
        if($state == 1){
            toLog("");
            toLog("reinforce:");
            moveReinforce();
        }
    }
    if($state != 1){
        if($use_map_specific_strategy != ""){
            toLog("");
            toLog("map specific stuff: $use_map_specific_strategy");
            mapSpecific($use_map_specific_strategy);
        }
        toLog("");
        toLog("block strategic regions adyacent to our bonuses");
        blockStrategics();
        toLog("");
        toLog("defend bonus:");
        defendBonus();          // 9        
        toLog("");
        toLog("break bonus:");
        breakBonus();           // 8/6     10
        toLog("");
        toLog("break run:");
        breakRun();             // 8       0
        toLog("");
        toLog("prevent bonus:");
        preventBonus();         // 4/7
        toLog("");
        toLog("destroy army:");
        destroyArmy();          // 2/6      7,8,9
        toLog("");
        toLog("complete bonus:");
        completeBonus();        // 5        5
        toLog("");
        toLog("explore bonus:");
        exploreBonus();         // 3        4
        toLog("");
        toLog("reinforce:");
        moveReinforce();        //          3
        toLog("");
        toLog("join stacks:");
        joinStacks();           // 7        1
        toLog("sucker punches:");
        suckerPunch();          //10        //10
                                         // good odds moves : 2/1        
    }
    toLog("");
    toLog("evaluation:");
    evaluateMoves();
}

function moveDeploy(){
    
    return deployToString();
}

function moveAttack(){
        
    return attackToString();
}

function openingMove(){
    global $map, $strat, $round;
    $adyacent_to_enemy = array();    
    $smallbonus = $map->smallestbonus;
    
    if(($map->income_one > 5) || ($map->income_two > 5)){
         toLog("abort openingMove! someone has income!");
         $strat->set_state(3);
        return 0;
    }
    if($round > 1){
        $my_prov = $map->strongest_province($map->my_regions);
        $adyacents = $map->has_adyacent($my_prov, $map->player_two);
        if(count($adyacents) > 1){
            toLog("abort openingMove! my region is bordered by more than 1 enemy!");
            return 0;
        }
        if(count($adyacents) < 1){
            toLog("abort openingMove! no enemy adyacent!");
            return 0;
        }
        $his_prov = $adyacents[0];
        $attack = 0;
        $end = 0;
        $start = 0;
        $my_armies = $map->regions[$my_prov]->armies;
        if($my_armies > ($map->income_one*6)){
            toLog("abort openingMove! stack too big");
            $strat->set_state(2);
            return 0;
        }
        $his_armies = $map->regions[$his_prov]->armies;
        if($his_armies > $my_armies){
            toLog("abort openingMove! his region has an advantage!");
            $strat->set_state(3);
            return 0;
        }
        if( ($his_armies + 2) < $my_armies){
            toLog("going for attack!");
            $attack = $my_armies + $map->income_one - 1;
            $end = $his_prov;
            $start = $my_prov;
        }else{
            toLog("keep piling up!");
        }
        $map->proposed_moves[] = new CMove(10, 5, 5, $my_prov, $attack, $start, $end, 1);
        return 1;
    }
    
    //check if one of the small bonuses has 2 enemy provinces in it and none of mine
    foreach ($map->bonuses as $bonus){
        $mine = count($map->prov_in_bonus($bonus->id, $map->player_one));
        $his = count($map->prov_in_bonus($bonus->id, $map->player_two));
        if( ( 1 < $his ) && ( $bonus->income == $smallbonus) && ( 1 > $mine ) ){
            // exit to normal...
            toLog("abort openingMove! bonus {$map->bonus_names[$bonus->id]} is soon owned by enemy!");
            if( 0 < count($map->prov_of_owner($map->prov_ady_to_bonus($bonus->id), $map->player_one))){
                $strat->add_guessed_bonus($bonus->id);
            }
            return 0;
        }
    }
    
    foreach ($map->my_regions as $region_id){
        if($map->has_adyacent($region_id, $map->player_two)){
            $bonus = $map->regions[$region_id]->bonus;
            if($map->bonuses[$bonus]->income == $smallbonus){
                $adyacent_to_enemy[] = $region_id;                
            }
        }
    }
    $count_of_adyacents = count($adyacent_to_enemy);
    toLogX("$count_of_adyacents of my good provinces have enemies adyacent: " . implode(",", $adyacent_to_enemy));
    switch($count_of_adyacents){
        case 0: 
            toLog("abnormal starting situation, abort openingMove!");
            return 0;
        case 1:{
            $map->proposed_moves[] = new CMove(10, 5, 5, $adyacent_to_enemy[0], 0, 0, 0, 1);
            break;
        }
        default:{
            $weight_attack_rand = 0;
            $weight_attack_east = 0;
            $weight_defend_both = 0;
            $weight_defend_one = 10;
            $choice_raw[0] = rand(0, $weight_defend_one);
            $choice_raw[1] = rand(0, $weight_defend_both);
            $choice_raw[2] = rand(0, $weight_attack_east);
            $choice_raw[3] = rand(0, $weight_attack_rand);            
            $choice = array_keys($choice_raw, max($choice_raw));
            toLog("opening choice: $choice[0]");
            
            switch ($choice[0]){
                case 0:{
                    shuffle($adyacent_to_enemy);
                    $map->proposed_moves[] = new CMove(10, 5, 5, $adyacent_to_enemy[0], 0, 0, 0, 1);
                    break;
                }
                case 1:{
                    $map->proposed_moves[] = new CMove(10, 2, 2, $adyacent_to_enemy[0], 0, 0, 0, 1);
                    $map->proposed_moves[] = new CMove(10, 3, 3, $adyacent_to_enemy[1], 0, 0, 0, 1);
                    break;
                }
                case 2:{
                    rsort($adyacent_to_enemy);
                    $start_id = $adyacent_to_enemy[0];
                    break;
                }
                case 3:{
                    rsort($adyacent_to_enemy);
                    $start_id = $adyacent_to_enemy[0];
                    break;
                }
            }            
            if(isset($start_id)){
                $enemy = $map->has_adyacent($start_id, $map->player_two);
                $enemy_id = $enemy[0];
                $map->proposed_moves[] = new CMove(10, 5, 5, $start_id, 5, $start_id, $enemy_id, 2);
                $other_region = -1;
                $other_target = -1;
                foreach ($map->my_regions as $my_region){
                    if(in_array($my_region, $adyacent_to_enemy)){continue;}
                    if($start_id != $my_region){
                        $other_region = $my_region;
                        $other_target = $map->regions[$other_region]->connections[0];
                    }
                }    
                if(($other_region < 0) || ($other_target < 1)){
                    break;
                }
                $map->proposed_moves[] = new CMove(10, 0, 0, $other_region, 1, $other_region, $other_target, 1);                
            }   
            break;
        }
    }
    return 1;
}

function mapSpecific($map_name){
    global $map, $strat, $round, $north_africa_block;
    
    // block north africa and take north america
    if(($map_name == "small_earth") && 
            ($map->bonuses[2]->owner == $map->player_one) &&
            ($map->bonuses[6]->owner == $map->player_two) && 
            ($map->regions[21]->owner == $map->player_one) &&
            ($map->income_two <= $map->income_one)){
        $map->block_region(21, 8);
        if($strat->get_state() == 5){
            toLogX("we'll wait out the enemy in north africa!");
            $map->send_sos(21, 10);
            $map->proposed_moves[] = new CMove(10, 7, 7, 21, 0, 0, 0, 0);
            $my_ady = $map->has_adyacent(21, $map->player_one);
            toLogX("my adyacent: " . implode(",", $my_ady));
            foreach ($my_ady as $ady) {
                $armies = $map->regions[$ady]->armies - 1;
                if($armies < 1){continue;}
                $map->proposed_moves[] = new CMove(10, 0, 0, $ady, $armies, $ady, 21, 0);
            }
        }
    }
    
    // catch a bot going for early europe
    if(($map_name == "small_earth") &&
            (!count($map->prov_in_bonus(6, $map->player_two))) &&
            (!count($map->prov_in_bonus(2, $map->player_two))) &&
            (!count($map->prov_in_bonus(6, "unknown"))) &&
            (!count($map->prov_in_bonus(2, "unknown"))) ){
        
        if(($round == 1) && ($map->regions[12]->owner == $map->player_one) ){
            toLogX("enemy going for europe... going for break!");
            $map->proposed_moves[] = new CMove(10, 5, 5, 12, 6, 12, 21, 0);
        }
        
        if(($round == 2) && ($map->regions[21]->owner == $map->player_one) ){
            toLogX("enemy going for europe... going for break!");
            $armies = $map->regions[21]->armies - 1 + 5;
            $weaker = ($map->regions[18]->armies < $map->regions[20]->armies ? 18 : 20);
            $map->proposed_moves[] = new CMove(10, 5, 5, 21, $armies, 21, $weaker, 0);
        }
    }
    
    // break north america if a bot is holding north africa
    if($map_name == "small_earth"){
        if(($map->bonuses[6]->owner == $map->player_one) &&
            ($map->bonuses[2]->owner == $map->player_two) && 
            ($map->regions[21]->owner == $map->player_two) &&
            ($map->regions[21]->armies >= ($map->income_two * 2)) &&
            ($map->income_two <= $map->income_one)){
            $my_africans = $map->has_adyacent(21, $map->player_one);
            $best_african = $map->strongest_province($my_africans);
            if( $map->regions[21]->armies >= ($map->regions[$best_african]->armies + 7) ){
                $north_africa_block = 0;
            }
            if($north_africa_block == 0){
                $north_africa_block = $map->regions[21]->armies;
            }elseif ( (($north_africa_block <= $map->regions[21]->armies) && 
                        ($map->regions[21]->armies < reqArmiesAttack($map->regions[$best_african]->armies)) ) || 
                    ($map->regions[$best_african]->armies < reqArmiesAttack($map->regions[21]->armies)) ) {
                $north_africa_block = $map->regions[21]->armies;
                $map->block_region($best_african, 8);
                $start = 0;
                $end = 0;
                if($map->regions[1]->owner == $map->player_one){
                    toLogX("north america map specific break ordered! we own alaska :)");
                }elseif ($map->regions[30]->owner == $map->player_one) {
                    toLogX("north america map specific break ordered! take alaska");
                    $start = 30;
                    $end = 1;
                }elseif ($map->regions[34]->owner == $map->player_one) {
                    toLogX("north america map specific break ordered! take kamchatka");
                    $start = 34;
                    $end = 30;
                }elseif ($map->regions[33]->owner == $map->player_one) {
                    toLogX("north america map specific break ordered! take mongolia");
                    $start = 33;
                    $end = 34;
                }elseif ($map->regions[38]->owner == $map->player_one) {
                    toLogX("north america map specific break ordered! take china");
                    $start = 38;
                    $end = 33;
                }else{
                    toLogX("north america map specific break ordered! take siam");
                    $start = 39;
                    $end = 38;
                }
                if( ($start > 0) && ($map->regions[$end]->owner == "neutral") ){
                    $armies = $map->regions[$start]->armies - 1;
                    $target_armies = $map->regions[$end]->armies;
                    $mindep = ($target_armies * 2) - $armies;
                    $map->proposed_moves[] = new CMove(6, $mindep, $map->income_one, $start, $armies + $mindep, $start, $end, 6);
                    $strat->set_state(3);
                }
            }else{
                $north_africa_block = $map->regions[21]->armies;
            }
        }
    }
}

function blockStrategics(){
    global $map;
    // no need to waste time defending bonuses too much if we have a big edge
    if($map->income_one > ($map->income_two * 3)){return 0;}
    
    $bonuses_to_check = $map->my_bonuses;
    if($map->bonus_taking_now > 0){
        $bonuses_to_check[] = $map->bonus_taking_now;
    }
    foreach ($bonuses_to_check as $bonus_id){
        toLogX("checking {$map->bonus_names[$bonus_id]}");
        $adyacent_regions = $map->prov_ady_to_bonus($bonus_id);
        foreach ($adyacent_regions as $region_id){
            // block everything except break bonus
            $priority = 8;
            
            $adyacent_enemies = $map->has_adyacent($region_id, $map->player_two);
            if(count($adyacent_enemies) < 1){continue;}
            
            if($map->regions[$region_id]->owner != $map->player_one){
                // call nearby stacks if any
                $nearby = $map->has_adyacent($region_id, $map->player_one);
                $nearby_stacks = array();
                foreach ($nearby as $nearby_id){
                    if(($map->regions[$nearby_id]->bonus != $bonus_id) 
                        && ($map->regions[$nearby_id]->armies > $map->income_one)
                        && (!in_array($nearby_id, $map->prov_ady_to_bonus($bonus_id)))){
                        $nearby_stacks[$nearby_id] = $map->regions[$nearby_id]->armies;
                    }
                }
                foreach ($nearby_stacks as $loc => $armies){
                    $map->proposed_moves[] = new CMove(9, 0, 0, $loc, $armies - 1, $loc, $region_id, 0);
                    $map->send_sos($region_id, 9);
                    toLogX("created move to reinforce strategic {$map->region_names[$region_id]} from {$map->region_names[$loc]}");
                }
                continue;
            }
            
            $strongest_enemy = $map->strongest_province($adyacent_enemies);
            $my_armies = $map->regions[$region_id]->armies;
            $his_armies = $map->regions[$strongest_enemy]->armies;
            
            // if no stack, lower priority
            if($his_armies < $map->income_one){$priority = 6;}
            if($my_armies < $map->income_one){$priority = 6;}
            
            // if he has a lot of armies, too dangerous
            if( ($his_armies * 0.8 ) > $my_armies ){continue;}
            
            // if i have a lot, normal attack are permitted
            if( ( ($his_armies * 2)  < $my_armies ) || 
                    ( ($my_armies > ($map->income_one * 3)) && ($my_armies > (reqArmiesAttack($his_armies))) ) ){
                $priority = 6;
            }
            
            $map->block_region($region_id, $priority);
            toLogX("strategic position priority $priority: {$map->region_names[$region_id]} vs {$map->region_names[$strongest_enemy]}");
        }
    }
}

function defendBonus(){
    global $strat, $map;
    $priority = 9;
    $adyacent_armies = 1;
    $defendables = array();
    $bonuses_to_defend = $map->my_bonuses;
    foreach ($bonuses_to_defend as $bonusid){
        foreach ($map->bonuses[$bonusid]->regions as $region){
            $adyacent_armies = 1;
            $threat_province = -1;
            $adyacent_enemy = $map->has_adyacent($region->id, $map->player_two);
            if (count($adyacent_enemy) < 1){continue;}
            foreach ($adyacent_enemy as $enemy_province){
                if ($adyacent_armies < $map->regions[$enemy_province]->armies){
                    $adyacent_armies = $map->regions[$enemy_province]->armies;
                    $threat_province = $enemy_province;
                }
            }
            $potential_enemies = $map->income_two + $adyacent_armies;
            $defenders = $map->armies_left_region[$region->id];
            toLogX("$defenders defenders, $potential_enemies attackers");
            if($potential_enemies > reqArmiesAttack($defenders + $map->income_one)){
                // if it's quite hopeless, lower priority
                $priority -= 2;
            }else{
                // if this province has other bonuses of ours adyacent, higher priority
                $adyacent_bonuses = $map->bonuses_bordering_prov($region->id);
                foreach ($adyacent_bonuses as $ady_bonus_id) {
                    if($map->bonuses[$ady_bonus_id]->owner == $map->player_one){
                        $priority += 1;
                    }
                }
            }
            $reinforce = $potential_enemies - reqArmiesAttack($defenders);
            if($reinforce < 1){
                $reinforce = 0;                
            }
            // take into account other provinces defending against this threat
            if(key_exists($threat_province, $map->threats_to_bonus)){
                $reinforce -= $map->threats_to_bonus[$threat_province];
            }else{
                $map->threats_to_bonus[$threat_province] = 0;
            }
            //
            if ($reinforce > $map->armies_left_deploy){
                $reinforce = $map->armies_left_deploy;
            }elseif($reinforce < 1){
                // we should be ok for defense, but lets set up a low priority reinforce anyways for good measure
                $defendables[] = new CMove(1 ,0 , $map->income_one, $region->id, 0, 0, 0, 0); 
                toLog("low priority defense move proposed at {$map->region_names[$region->id]}");
                continue;
            }
            // to defend a bonus we set up one order per army, to make sure all available armies are used here
            for($i = 0; $i < $reinforce; $i++){
                $defendables[] = new CMove($priority ,1 , 1, $region->id, 0, 0, 0, 0);                
            }
            $map->block_region($region->id, $priority);
            $map->send_sos($region->id, $priority);
            $map->threats_to_bonus[$threat_province] += ( $reinforce + $defenders );
            toLog("defense move proposed: deploy {$reinforce} at {$map->region_names[$region->id]}");
        }
    }
    shuffle($defendables);
    $map->proposed_moves = array_merge($map->proposed_moves, $defendables);
}

function breakBonus(){
    global $map, $strat;
    $targets = array();      // holds moves
    $map->bonuses_breaking_now = array();
    //$priority = 8;
    $priority = 8;
    $delay = 10;
    toLogX("enemy bonuses: " . implode(" ", $map->enemy_bonuses));
    // check enemy bonuses for opportunities to break
    foreach ($map->enemy_bonuses as $bonusid){
        $plusminus = -999;          // keep track of our numerical advantage
        $best_prov_b = -1;          // best site to attack the bonus from
        $best_target = -1;          
        
        //find a province in this bonus that we can break
        foreach ($map->bonuses[$bonusid]->regions as $region){
            $adyacent_mine = $map->has_adyacent($region->id, $map->player_one);     // returns array[int]
            if(count($adyacent_mine) < 1){continue;}
            toLogX("my provinces next to {$map->region_names[$region->id]}: " . implode(" ", $adyacent_mine));
            foreach ($adyacent_mine as $my_province){
                $adyacent_his = $map->has_adyacent_inbonus($my_province, $map->player_two, $bonusid);     // returns array[int]
                if(count($adyacent_his) < 1){continue;}
                toLogX("his provinces next to {$map->region_names[$my_province]}: " . implode(" ", $adyacent_his));
                foreach ($adyacent_his as $his_province){
                    $def = $map->regions[$his_province]->armies;
                    $att = $map->regions[$my_province]->armies;
                    $this_plusminus = $att - $def;
                    // factor in stacks he might have in the vicinity
                    $his_adyacent = $map->has_adyacent($his_province, $map->player_two);
                    foreach ($his_adyacent as $adyacent){
                        if($map->regions[$adyacent]->bonus != $bonusid){
                            $reinforce_armies = $map->regions[$adyacent]->armies - 1;
                            $this_plusminus -= ($reinforce_armies / 3);
                        }
                    }
                    if ($plusminus < $this_plusminus ){
                        $best_prov_b = $my_province;
                        $best_target = $his_province;
                        $plusminus = $this_plusminus;
                    }
                }
            }
        }
        // if we have no target, maybe we are just one region away?
        if (($best_prov_b < 0) || ($best_target < 0)){
            $plusminus = -999;
            $adyacent_tobonus = $map->prov_ady_to_bonus($bonusid);
            toLog("cant break {$map->bonus_names[$bonusid]} directly, trying adyacents: " . implode(" ", $adyacent_tobonus));
            foreach ($adyacent_tobonus as $region_id){
                $adyacent_mine = $map->has_adyacent($region_id, $map->player_one);     // returns array[int]
                foreach ($adyacent_mine as $my_province){
                    $def = $map->regions[$region_id]->armies;
                    $att = $map->regions[$my_province]->armies;
                    if ($plusminus < ($att - $def) ){
                        $best_prov_b = $my_province;
                        $best_target = $region_id;
                        $plusminus = $att - $def;
                    }
                }
            }
        }
        if (($best_prov_b < 0) || ($best_target < 0)){
            continue;
        }
        $available_armies = $map->regions[$best_prov_b]->armies;
        $defenders = $strat->predict_deploy($best_target) + $map->regions[$best_target]->armies;
        $max_armies = $available_armies + $map->income_one - 1;
        $req = intval(reqArmiesAttackCrit($defenders));
        // if we dont have enough armies to break directly, maybe one of the adyacent regions is better suited?
        if ($max_armies < $req){
            $most_conn = count($map->has_adyacent_inbonus($best_prov_b, $map->player_two, $bonusid));
            $best_option = -1;
            foreach($map->regions[$best_prov_b]->connections as $conn){
                if($map->regions[$best_target]->armies < $map->regions[$conn]->armies){continue; }
                $attack_options = count($map->has_adyacent_inbonus($conn, $map->player_two, $bonusid));
                if($attack_options > $most_conn){
                    $most_conn = $attack_options;
                    $best_option = $conn;
                }
            }
            if($best_option > 0){
                $best_target = $best_option;
                $available_armies = $map->regions[$best_prov_b]->armies;
                $defenders = $strat->predict_deploy($best_target) + $map->regions[$best_target]->armies;
                $max_armies = $available_armies + $map->income_one - 1;
                $req = intval(reqArmiesAttack($defenders));
                toLog("trying to break {$map->bonus_names[$bonusid]} by moving to {$map->region_names[$best_prov_b]}");
                $map->bonuses_breaking_now[] = $bonusid;
            }
        }
        if ($max_armies < $req){
            toLog("not enough armies to break {$map->bonus_names[$bonusid]} at {$map->region_names[$best_target]} from {$map->region_names[$best_prov_b]} ({$max_armies} vs {$defenders}, required {$req})");
            $map->send_sos($best_prov_b, $priority);
            continue;
        }
        $req_deploy = nonZero(($req - ($available_armies)) + 1);
        $map->block_region($best_prov_b, $priority);
        if($map->regions[$best_target]->owner == "neutral"){
            $delay = 2;
        }
        // if we dont have any stack, lower priority
        if($map->regions[$best_prov_b]->armies < $map->income_two){
            $priority -= 2;
        }
        $targets[] = new CMove($priority ,$req_deploy , $req_deploy, $best_prov_b, $available_armies - 1, $best_prov_b , $best_target, $delay);
        
        toLog("break proposed priority $priority: deploy $req_deploy - {$map->income_one} at {$map->region_names[$best_prov_b]} and attack {$map->region_names[$best_target]}");
        // we deploy more armies if we have spare
        $priority = 4;
        $max_surplus_deploy = $map->income_one - $req_deploy;
        if($map->regions[$best_target]->owner == "neutral"){
            // we have a chance to surprise him
            $priority = 6;
        }
        for($i = 0; $i < $max_surplus_deploy; $i++){
            $targets[] = new CMove($priority ,1 , 1, $best_prov_b, 0, 0, 0, 0);
        }
        $map->bonuses_breaking_now[] = $bonusid;
    }
    // 
    $map->proposed_moves = array_merge($map->proposed_moves, $targets);
    
}

function breakRun(){
    global $map, $strat, $round;
    if($round == 1){
        return 0;
    }
    $priority = 7;
    $targets = array();      // holds moves
    $stacks = array();
    
    foreach ($map->my_regions as $region_id){
        $stacks[$region_id] = $map->regions[$region_id]->armies;
    }
    
    foreach ($map->enemy_bonuses as $bonus_id){
        toLogX("trying to run-break {$map->bonus_names[$bonus_id]}");
        if(in_array($bonus_id, $map->bonuses_breaking_now)){
            toLogX("we're already trying to break this bonus directly");
            continue;
        }
        $adyacents = array();
        $adyacents = $map->prov_ady_to_bonus($bonus_id);
        $adyacents = $map->prov_of_owner($adyacents, $map->player_one);
        if ( count($adyacents) > 0 ){
            toLogX("we own an adyacent!");
            continue;
        }
        $closest_stack = -1;
        $closest_dist = 999;
        $best_tile = -1;
        $blocked = array();
        $blocked = $map->get_blocked(6);
        foreach ($stacks as $loc => $armies){
            if($armies == 0){continue;}
            if(in_array($loc, $blocked)){continue;}
            $stack_bonus = $map->regions[$loc]->bonus;
            if( ($map->bonuses[$stack_bonus]->owner == $map->player_one) && ($map->has_adyacent($loc, $map->player_two)) ){
                continue;
            }
            $path = $map->path_to_break($loc, $bonus_id, $armies);
            $dist = count($path);
            // 0 dist means no path
            if($dist > 0){
                // if a neutral has 1 army, favor it
                if($map->regions[$path[0]]->armies == 1){
                    $dist--;
                }
                // slightly favor stacks over empty provinces for starting run breaks
                if($armies < ($map->income_one*2)){
                    $dist++;
                    if($armies < $map->income_one){
                        $dist++;
                    }
                }
                if($dist < $closest_dist){
                    $closest_stack = $loc;
                    $closest_dist = $dist;
                    $best_tile = $path[0];
                }
            }
        }
        toLogX("closest stack is at $closest_stack (dist: $closest_dist), path through $best_tile");
        if($closest_stack == -1){
            toLogX("no path/stack found");
            continue;
        }
        if($closest_dist > ($round - 2)){
            toLogX("path too long for current game-state");
            continue;
        }
        if($closest_dist > 2){
            $priority = 4;
        }
        if($map->check_for_give_away_bonus($closest_stack, $best_tile)){
            $provinces_in_bonus = 0;
            foreach ($map->my_bonuses as $bonus_id){
                $provinces_in_bonus += count($map->bonuses[$bonus_id]->regions);
            }
            // if we have other provinces...
            if ($provinces_in_bonus < count($map->my_regions)){
                toLogX("break run from here would give away our bonus...");
                continue;
            }
        }
        toLogX("run break: moving stack from {$map->region_names[$closest_stack]} to {$map->region_names[$best_tile]}");
        $armies = $stacks[$closest_stack] - 1;
        $stacks[$closest_stack] = 0;
        $target_armies = $map->regions[$best_tile]->armies;
        $req_attack_force = reqArmiesAttack($target_armies);
        $min_dep = $req_attack_force + 1;
        if($min_dep < 0){$min_dep = 0;}
        if($min_dep > $map->income_one){$min_dep = $map->income_one;}
        $targets[] = new CMove($priority, $min_dep, $map->income_one, $closest_stack, $armies + $min_dep, $closest_stack, $best_tile, 0);
    }
    $map->proposed_moves = array_merge($map->proposed_moves, $targets);
}

function suckerPunch(){
    global $map;
    $priority = 8;
    $delay = 10;
    
    if(count($map->my_bonuses) < 1){return 0;}

    // check if any continents are fully owned by us except a single province
    $candidate_list = array();
    foreach ($map->bonuses as $bonus){
        $my_provs = $map->prov_in_bonus($bonus->id, $map->player_one);
        $his_provs =  $map->prov_in_bonus($bonus->id, $map->player_two);
        $total_prov_count = count($bonus->regions);
        if ( (count($his_provs) == 1) && ( ( count($his_provs) + count($my_provs )) == $total_prov_count) ){
            $candidate_list[$bonus->id] = $his_provs[0];
        }
    }
    // now check those single enemy provinces if they have adyacent another bonus fully owned by us and they will attack
    $good_candidates = array();
    foreach ($candidate_list as $bonus => $region){
        $valid = false;
        $adyacents = $map->has_adyacent($region, $map->player_one);
        foreach ($adyacents as $adyacent){
            $ady_bonus = $map->regions[$adyacent]->bonus;
            if( ($map->bonuses[$ady_bonus]->owner == $map->player_one) 
                && ($map->regions[$region]->armies > ($map->regions[$adyacent]->armies + $map->income_one)) ){
                $valid = true;
            }
        }
        if($valid){
            $good_candidates[$bonus] = $region;
        }
    }
    // now... sucker punch!
    $punches = array();
    foreach ($good_candidates as $bonus => $region){
        $starts = $map->has_adyacent_inbonus($region, $map->player_one, $bonus);
        shuffle($starts);
        $punches[] = new CMove(10, 3, 3, $starts[0], 2, $starts[0], $region, 10);
        toLogX("proposed sucker punch at {$map->region_names[$region]}");
    }
    $map->proposed_moves = array_merge($map->proposed_moves, $punches);
    
}

function preventBonus(){
    global $map, $strat, $mreg, $round;
    $defendables = array();
    foreach ($map->bonuses as $bonus){
        
        // gather info and dismiss some scenarios
        $my_provinces = $map->prov_in_bonus($bonus->id, $map->player_one);
        $my_prov_count = count($my_provinces);
        $his_provinces = $map->prov_in_bonus($bonus->id, $map->player_two);
        $his_prov_count = count($his_provinces);
        $neutral_provinces = $map->prov_in_bonus($bonus->id, "neutral");
        $neutral_prov_count = count($neutral_provinces);
        if ( $my_prov_count < 1 ){
            continue;
        }
        if ( ($neutral_prov_count > $his_prov_count) ){
            if($bonus->income > $map->smallestbonus){
                continue;
            }
        }
        
        // if at least one of our provinces in this bonus has no adyacent enemies, we're safe
        $one_safe_prov = 0;
        foreach ($my_provinces as $my_prov){
            if ( count($map->has_adyacent($my_prov, $map->player_two)) < 1 ){
                $one_safe_prov = 1;
            }
        }
        if ($one_safe_prov == 1){ continue; }
        
        toLogX("considering {$map->bonus_names[$bonus->id]}...");
        
        // our strongest province in this bonus and his strongest
        $my_province = $map->strongest_province($my_provinces);
        $my_armies = $map->regions[$my_province]->armies;
        $his_province = $map->strongest_province($map->has_adyacent($my_province, $map->player_two));
        $his_armies = $map->regions[$his_province]->armies;
        
        // if he doesnt pose a threat
        if (reqArmiesAttack($my_armies) > ($his_armies + $map->income_two)){
            $map->block_region($my_province, 6);
            toLogX("defense at {$my_province} not threatened");
            continue;            
        }
        
        if($my_prov_count > 1){
            $max_deploy = 2;
            $priority = 4;
        }else{
            // if we can predict his attack we do so, else we assume maximum
            $his_predicted_attack = $strat->predict_attack($my_province);
            if($his_predicted_attack > 0){
                $max_his = $his_predicted_attack;
            }else{
                $max_his = $his_armies + $map->income_two - 1;
            }
            $my_max_defense = $my_armies + $map->income_one;
            
            toLogX("defense at {$map->region_names[$my_province]}: ($max_his attackers vs max $my_max_defense my defenders)");
            
            $badfight = false;
            //check wether holding the position will give us a bad fight
            if($max_his > ($my_max_defense * 2)){
                // he's too strong!
                toLogX("no prevent possible, more than twice my armies");
                $badfight = true;
            }
            if( $max_his > reqArmiesAttack($my_max_defense) ){
                // he's attacked before and has good odds!
                toLogX("no prevent possible, he has good odds");
                $badfight = true;
            }
            if(!$badfight){
                $armies_needed = reqArmiesDefend($max_his);                                
                $max_deploy = intval($armies_needed - $my_armies);
                $priority = 6;
                if($my_province == $map->strongest_province($map->my_regions)){
                    $priority = 7;
                }
            }else{
                // run prevent
                $adyacent_in_bonus = $map->has_adyacent_inbonus($my_province, $map->player_two, $bonus->id);
                $empty_adyacent = array();
                foreach ($adyacent_in_bonus as $adyacent){
                    if($map->regions[$adyacent]->armies == 1){
                        $empty_adyacent[] = $adyacent;
                    }
                }
                shuffle($empty_adyacent);
                if(count($empty_adyacent) > 0){
                    $target = $empty_adyacent[0];
                    $dep = nonZero(3 - $my_armies);
                    $att = $my_armies - 1 + $dep;
                    $priority = 7;
                    $delay = 1;
                }elseif ($my_armies > 4){
                    $empty_adyacent = $map->has_adyacent_inbonus($my_province, "neutral", $bonus->id);
                    if(count($empty_adyacent) > 0){
                        shuffle($empty_adyacent);
                        $target = $empty_adyacent[0];
                        $dep = 0;
                        $att = $my_armies - 1;
                        $priority = 7;
                        $delay = 1;
                    }
                }
                if(count($empty_adyacent) > 0){
                    $defendables[] = new CMove($priority, $dep, $dep, $my_province, $att, $my_province, $target, $delay);
                    toLogX("proposed run defend at {$map->region_names[$my_province]}");
                }
                continue;
            }
        }
        if($max_deploy > $map->income_one){
            $max_deploy = $map->income_one;
        }
        if($max_deploy < 1){
            continue;
        }
        // to defend a bonus we set up one order per army, to make sure all available armies are used here
        for($i = 0; $i < $max_deploy; $i++){
            $defendables[] = new CMove($priority, 1, 1, $my_province, 0, 0, 0, 0);                
        }
        $map->send_sos($my_province, $priority);
        $map->block_region($my_province, $priority);
        toLog("proposed to deploy max {$max_deploy} armies at {$map->region_names[$my_province]} to prevent enemy completing bonus {$bonus->id}");
        
    }
    shuffle($defendables);
    $map->proposed_moves = array_merge($map->proposed_moves, $defendables);
}

function destroyArmy(){
    global $map, $strat;
    $destroyables = array();
    foreach($map->my_regions as $region_id){
        $adyacent_enemies = $map->has_adyacent($region_id, $map->player_two);
        if (!count($adyacent_enemies)){
            continue;
        }else{
            shuffle($adyacent_enemies);
            usort($adyacent_enemies, "regionByArmies");
            foreach ($adyacent_enemies as $target_id){
                // sort priorities
                $priority = 6;
                $his_armies = $map->regions[$target_id]->armies;
                $my_armies = $map->regions[$region_id]->armies - 1;
                if( ($map->income_one > $map->income_two) && ($my_armies > ($map->income_one*10)) ){
                    $priority = 7;
                }
                // check if this is the best province to attack from
                $mine_adyacent = $map->has_adyacent($target_id, $map->player_one);
                $mine_adyacent_non_blocked = array();
                foreach ($mine_adyacent as $ady){
                    if(!in_array($ady, $map->get_blocked($priority))){
                        $mine_adyacent_non_blocked[] = $ady;
                    }
                }
                $strongest_mine_adyacent = $map->strongest_province($mine_adyacent_non_blocked);
                if($strongest_mine_adyacent != $region_id){
                    continue;
                }
                
                $predicted_deploy = $strat->predict_deploy($target_id);
                toLogX("predicted $predicted_deploy");
                // normal attack                
                toLogX("his armies : $his_armies predicted deploy : $predicted_deploy");
                $attack_str = reqArmiesAttackCrit($his_armies + $predicted_deploy);
                toLogX("attack str: $attack_str ");
                $delay = 9;
                $need_deploy = nonZero(($attack_str + 1 ) - $my_armies);
                if ($need_deploy > $map->income_one){
                    $my_armies += 1;
                    $potential_attackers = $his_armies + $predicted_deploy - 1;
                    $necessary_defenders = reqArmiesDefend($potential_attackers);
                    $need_deploy = nonZero($necessary_defenders - $my_armies);
                    if ($need_deploy > $map->income_one){
                        // we are facing a bad fight, the only time we want to take it anyways is when we have a big stack
                        $deficit = $need_deploy - $map->income_one;
                        if($deficit > ($my_armies / 10)){
                            $need_deploy = 0;
                            toLogX("too weak to defend position at {$map->region_names[$region_id]}, need $deficit more than available");
                            if($my_armies > 10){
                                // run !
                                $target = -1;
                                $adyacents_his = $map->has_adyacent($region_id, $map->player_two);
                                foreach ($adyacents_his as $adyacent){
                                    if(($map->regions[$adyacent]->armies * 2) < $my_armies){
                                        $target = $adyacent;
                                    }
                                }
                                if ($target < 0){
                                    $adyacent_neut = $map->has_adyacent($region_id, "neutral");
                                    foreach ($adyacent_neut as $adyacent){
                                        $target = $adyacent;
                                    }
                                }
                                if($target > 0){
                                    $map->proposed_moves[] = new CMove(5, 0, 0, $region_id, $my_armies - 1, $region_id, $target, 1);
                                    toLogX("running away from {$map->region_names[$region_id]} to {$map->region_names[$target]}");
                                }
                            }
                        }
                    }
                    if($need_deploy > 0){
                        toLogX("defending stack at {$map->region_names[$region_id]}: potential attackers: $potential_attackers, necessary defenders: $necessary_defenders");
                        for($i = 0; $i < $need_deploy; $i++){
                            $destroyables[] = new CMove($priority, 1, 1, $region_id, 0, 0, 0, 0);
                        }
                        toLog("proposed to prepare attack/defend stack at " 
                            . "{$map->region_names[$region_id]} (deploy $need_deploy) priority $priority");                         
                    }           
                }else{                    
                    $max_deploy = $map->income_one;
                    // avoid overcommitting against 1 army territories
                    if($attack_str < 4){
                        $max_deploy = $need_deploy + 1;
                    }
                    $destroyables[] = new CMove($priority, $need_deploy, $max_deploy, $region_id, 
                            $attack_str, $region_id, $target_id, $delay);
                    toLog("proposed to destroy enemy at {$map->region_names[$target_id]}, from " 
                        . "{$map->region_names[$region_id]} (deploy {$need_deploy}, attack with {$attack_str}) priority $priority");
                }
            }
        }
    }
    // first we check wether more than 1 attack is issued from any province, and if so we select the highest priority
    $unique_destroyables = array();
    $attack_origins = array();
    $count = 0;
    foreach ($destroyables as $destroyable){
        $origin = $destroyable->attack_start;
        if((key_exists($origin, $attack_origins)) 
                && ($destroyables[$attack_origins[$origin]]->priority < $destroyable->priority) ){
            toLog("replacing other attacks from $origin with the one headed to $destroyable->attack_end");
            $unique_destroyables[$attack_origins[$origin]] = $destroyable;
        }else{
            $unique_destroyables[] = $destroyable;
            $attack_origins[$origin] = $count;
        }
        $count++;
    }
    
    //then we check wether more than one attack is issued to any target, and if so we only leave the one that requires the 
    // smallest deploy
    $attack_targets = array();
    foreach ($unique_destroyables as $attack_move){
        $target = $attack_move->attack_end;
        $reinforcements_needed = $attack_move->deploy_min;
        if(key_exists($target, $attack_targets)){
            if($attack_targets[$target] > $reinforcements_needed){
                $attack_targets[$target] = $reinforcements_needed;
            }
        }else{
            $attack_targets[$target] = $reinforcements_needed;
        }
    }
    $final_destroyables = array();
    foreach ($unique_destroyables as $attack_move){
        if ($attack_move->deploy_min > ($attack_targets[$attack_move->attack_end])){
            toLogX("discarded attack move against $attack_move->attack_end from $attack_move->attack_start");
            continue;
        }
        $attack_exists = false;
        foreach ($map->proposed_moves as $p_move){
            // if anoher higher priority attack exists with identical start and end, skip
            if ( ($p_move->priority > $attack_move->priority) && ($p_move->attack_start == $attack_move->attack_start) 
                    && ($p_move->attack_end == $attack_move->attack_end) ){
                $attack_exists = true;
            }
        }
        if($attack_exists == true){continue;}
        $final_destroyables[] = $attack_move;
    }
    $map->proposed_moves = array_merge($map->proposed_moves, $final_destroyables);
}

function completeBonus(){
    global $map;
    $completables = array();
    
    $bestbonus = $map->bonus_taking_now;
    if($bestbonus > 0){
        // sort out priorities...
        $priority = 5;
        $neutrals = $map->prov_in_bonus($bestbonus, "neutral");
        if (count($neutrals) < 2){
            $priority += 2;
        }
        if($map->income_two > $map->income_one){
            $priority += 2;
        }
        
        $income_total = $map->income_one;
        $available_local_armies = array();
        foreach ($neutrals as $target_id){
            toLogX("checking if we can take {$map->region_names[$target_id]}");
            // find a suitable province to attack the neutral from
            $potential_starts = $map->has_adyacent($target_id, $map->player_one);
            // check if any are blocked
            $good_starts = array();
            foreach ($potential_starts as $start){
                // only advance unblocked
                if($map->blocked_regions[$start] < $priority){
                    $good_starts[] = $start;
                }
            }
            // but if NO unblocked province is adyacent, try anyways with blocked as it will likely get thrown out later
            if(count($good_starts) < 1){
                $good_starts = $potential_starts;
            }
            $best_province = $map->strongest_province_alt($good_starts, $available_local_armies);
            if (!array_key_exists($best_province, $available_local_armies)){
                $available_local_armies[$best_province] = $map->regions[$best_province]->armies;
            }
            if($map->regions[$target_id]->armies == 1){
                $armies_need_att = 2;
            }else{
                $armies_need_att = 4;
            }
            $armies_need_dep = nonZero($armies_need_att - ($available_local_armies[$best_province] - 1));
            $min_dep = $armies_need_dep;
            if (($armies_need_att == 4) && ($armies_need_dep > 0)){
                $armies_need_att = 3;
                $min_dep--;
            }
            toLogX("need to deploy $min_dep to attack with $armies_need_att from {$map->region_names[$best_province]}");
            if ($min_dep <= $income_total){
                $income_total -= $armies_need_dep;
                $available_local_armies[$best_province] -= ($armies_need_att - $min_dep);
                $completables[] = new CMove($priority, $min_dep, $armies_need_dep , $best_province, 
                        $armies_need_att, $best_province, $target_id, 5);
                toLog("proposed to attack {$target_id} from {$best_province} with {$armies_need_att} armies to complete bonus {$bestbonus}");
            }
        }
        $map->proposed_moves = array_merge($map->proposed_moves, $completables);
    }
}

function exploreBonus(){
    // when we don't know a bonus entirely, we want to explore it before taking it
    global $map, $strat;
    
    // no exploration during all out war or mopup
    $state = $strat->get_state();
    if(($state == 3) || ($state == 4) ){return 0;}
    // if the game is deadlocked and we have an income advantage, sit on it
    if(($state == 2) && ($map->income_one > ($map->income_two + 1))){
        return 0;
    }
    
    //if we have the income edge, sit on it (not anymore since new pathing...)
    //if($map->income_one > $map->income_two){return 0;}
    
    $explorables = array();
    foreach($map->bonuses as $bonus){
        $attitude = $strat->get_bonus_attitude($bonus->id);
        if($attitude != 2){continue;}
        
        toLogX("considering expansion possibilities in {$map->bonus_names[$bonus->id]}");
        
        $my_provinces = $map->prov_in_bonus($bonus->id, $map->player_one);
        $priority = 3;
        if(count($my_provinces) == 0){
            $priority = 1;        
        }
        
        $neutrals = $map->prov_in_bonus($bonus->id, "neutral");
        $target_id = -1;
        $best_province = -1;
        $most_value = 0;
        
        // find the best neutral to explore
        foreach ($neutrals as $neutral_id){
            //if it doesnt have adyacent unknown or enemy, it doesnt help us
            $unknown_adyacent = $map->has_adyacent_inbonus($neutral_id, "unknown", $bonus->id);
            $enemy_adyacent = $map->has_adyacent_inbonus($neutral_id, $map->player_two, $bonus->id);
            if ((count($unknown_adyacent) == 0) && (count($enemy_adyacent) == 0)){continue;}
                
            $my_adyacent = $map->has_adyacent($neutral_id, $map->player_one);
            $my_blocked = $map->get_blocked($priority);
            $my_non_blocked = array_diff($my_adyacent, $my_blocked);
            if (count($my_non_blocked) < 1){ continue; }
            $best_province_here = $map->strongest_province($my_non_blocked);
            // if no province to attack from, skip
            if($best_province_here < 1){continue;}
            // one again we bail if we have enemies nearby
            if ($map->has_adyacent($best_province_here, $map->player_two)){continue;}
            
            $my_adyacent_armies = $map->regions[$best_province_here]->armies;
            
            $value = $my_adyacent_armies + count($map->regions[$neutral_id]->connections);
            
            $other_bonuses = count($map->bonuses_bordering_prov($neutral_id));
            $value += ($other_bonuses * 3);
            
            if ($value > $most_value){
                $most_value = $value;
                $target_id = $neutral_id;
                $best_province =$best_province_here;
            }
        }
        if ($target_id > -1){
            toLogX("best province to explore: {$map->region_names[$target_id]} from {$map->region_names[$best_province]}");
            
            $armies_attack = reqArmiesAttack($map->regions[$target_id]->armies);
            $armies_deploy = nonZero($armies_attack - $map->regions[$best_province]->armies + 1);
            $explorables[] = new CMove($priority, $armies_deploy, $armies_deploy, $best_province, $armies_attack, 
                    $best_province, $target_id, 4);
            toLog("proposed to attack {$target_id} from {$best_province} to explore bonus {$bonus->id} priority $priority");            
        }else{
            toLogX("no suitable target found in bonus {$map->bonus_names[$bonus->id]}"); 
        }
    }
    $map->proposed_moves = array_merge($map->proposed_moves, $explorables);
}

function moveReinforce(){
    global $map, $strat;
    $reinforceables = array();
    $join_up_in_bonus_taking = array();
    foreach ($map->my_regions as $region_id){        
        $target = -1;
        
        $armies_to_move = $map->regions[$region_id]->armies - 1;
        if ($armies_to_move < 1){continue;}
        
        // if we're next to an enemy skip
        if ($map->has_adyacent($region_id, $map->player_two)){continue;}   
                
        // if we're next to a neutral inside a bonus we want to take we dont transfer
        $bonus = $map->regions[$region_id]->bonus;
        if ($map->has_adyacent_inbonus($region_id, "neutral", $bonus)){
            $attitude = $strat->get_bonus_attitude($bonus);
            if(($attitude == 5) || ($attitude == 2)){
                $strongest_in_bonus = $map->strongest_province($map->prov_in_bonus($bonus, $map->player_one));
                // under certain circumstances make armies join up inside a bonus
                if( ($strongest_in_bonus != $region_id) &&
                    ($map->regions[$strongest_in_bonus]->armies < 5) &&
                    (in_array($strongest_in_bonus, $map->has_adyacent($region_id, $map->player_one))) &&
                    ($map->has_adyacent_inbonus($strongest_in_bonus, "neutral", $bonus)) && 
                    (!in_array($region_id, $join_up_in_bonus_taking)) ){
                    
                    $target = $strongest_in_bonus;
                    toLogX("joining up armies inside bonus {$map->bonus_names[$bonus]}");
                    $join_up_in_bonus_taking[] = $target;
                }else{
                    continue;
                }               
            }
        }    
        
        // if we know enemy regions
        if($target < 0){
            if($map->any_prov_of_owner_known($map->player_two)){
                $path_to_enemy = (array)$map->path_to_owned_by($region_id, $map->player_two);
                if(count($path_to_enemy) > 0){
                    $target = $path_to_enemy[0];
                }
            }else{
                // if no enemies, just go anywhere that's not surrounded by ours
                if ($map->has_adyacent($region_id, "neutral")){continue;} 
                $target = $map->path_to_not_owned_by($region_id, $map->player_one);
            }            
        }
        if($target < 0){ continue;}
        
        // if the path goes through neutrals, make sure we have troops to do that
        if($map->regions[$target]->owner == "neutral"){
            if($armies_to_move < 4){continue;}
        }
        if($map->check_for_give_away_bonus($region_id, $target)){
            toLogX("reinforcing {$map->region_names[$target]} would give away our bonus...");
            continue;
        }
        
        $priority = 1;
        $delay = 4;
        // reinforce regions next to enemies first
        if($map->has_adyacent($target, $map->player_two)){
            $delay = 3;
        }
        if($map->answer_sos($region_id) > 0){
            $target = $map->answer_sos($region_id);
            $priority = $map->sos_call[$target];
            $delay = 1;
        }
        $reinforceables[] = new CMove($priority, 0, 0, $region_id, $armies_to_move, $region_id, $target, $delay);
        toLog("proposed to transfer {$armies_to_move} armies from {$region_id} to {$target} (priority{$priority})");
    }
    usort($reinforceables, "attStr");
    $map->proposed_moves = array_merge($map->proposed_moves, $reinforceables);
}

function joinStacks(){
    $priority = 8;
    global $map, $strat;
    $joinables = array();
    $my_stacks = $strat->get_my_stacks();
    if (count($my_stacks) < 2){return 0;}
    $possible_join_locations = array_fill(1, count($map->regions), 0);
    foreach ($my_stacks as $loc => $armies){
        $adyacents = $map->has_adyacent($loc, -1);
        foreach ($adyacents as $adyacent){
            if($map->regions[$adyacent]->armies < ($armies/2)){
                $possible_join_locations[$adyacent]++;
            }
        }
    }
    $join_locations = array();
    foreach ($possible_join_locations as $loc => $val){
        if($val > 1){
            $join_locations[] = $loc;
        }
    }
    foreach ($join_locations as $loc){
        $adyacents = $map->has_adyacent($loc, $map->player_one);
        foreach ($adyacents as $adyacent){
            $armies = $map->regions[$adyacent]->armies - 1;
            $joinables[] = new CMove($priority, 0, 0, $adyacent, $armies, $adyacent, $loc, 2);
            toLogX("proposed to join stacks at {$map->region_names[$loc]}");
        }
    }
    $map->proposed_moves = array_merge($map->proposed_moves, $joinables);    
    return 0;
}

function evaluateMoves(){
    $goodmoves = array();
    global $map;
    $map->final_moves = array();
    $moves = $map->proposed_moves;
    foreach ($moves as $move){
        toLogX("proposed move: P: $move->priority D: $move->delay DEPMIN: $move->deploy_min DEPMAX: $move->deploy_max L: {$map->region_names[$move->deploy_loc]}" . 
                " ATT: $move->attack_amount -> {$map->region_names[$move->attack_end]}");
    }
    toLogX("armies left per region: " . implode(" ", $map->armies_left_region) . " regions: " . implode(" ", array_keys($map->armies_left_region)));
    $priority = 10;
    $biggest_attack = 0;
    // go distributing armies to our attacks depending on priority
    while($priority > 0){
        foreach ($moves as $move){
            if ($move->priority != $priority){continue;}
            if ($move->deploy_min > $map->armies_left_deploy){
                toLogDiscardMove("insufficient armies left to deploy", $move);
                continue;                
            }
            if ($move->attack_amount > 0){
                if(($map->armies_left_region[$move->attack_start] + $move->deploy_min)
                    <= ($move->attack_amount)) {
                    toLogDiscardMove("not enough armies for attack", $move);
                    continue;                    
                }
                if(array_key_exists($move->attack_start, $map->blocked_regions)){
                    if($map->blocked_regions[$move->attack_start] > $priority){
                        toLogDiscardMove("region blocked with priority {$map->blocked_regions[$move->attack_start]} !", $move);
                        continue;                        
                    }                    
                }
                $map->armies_left_region[$move->attack_start] -= $move->attack_amount;              
            }
            $map->armies_left_deploy -= $move->deploy_min;
            $map->armies_left_region[$move->deploy_loc] += $move->deploy_min;
            // if we have a very big army, we probably dont want to split it
            if($move->attack_amount > 20){
                $move->attack_amount += $map->armies_left_region[$move->attack_start];
                $map->armies_left_region[$move->attack_start] = 0;
                $map->blocked_regions[$move->attack_start] = $move->priority;
            }
            $goodmoves[] = $move;
        }
        $priority -= 1;        
    }
    
    // if we have spare armies, raise some attacks
    toLog("$map->armies_left_deploy armies left to deploy entering spare army distribution");
    usort($goodmoves, "priority");
    foreach ($goodmoves as $move){
        if ($map->armies_left_deploy > 0){
            if ($move->deploy_min < $move->deploy_max){
                $can_raise = $move->deploy_max - $move->deploy_min;
                if ($can_raise > $map->armies_left_deploy){
                    $can_raise = $map->armies_left_deploy;
                    $move->deploy_max = $move->deploy_min + $map->armies_left_deploy;
                    $map->armies_left_deploy = 0;
                }else{
                    $move->deploy_max = $move->deploy_min + $can_raise;
                    $map->armies_left_deploy -= $can_raise;
                }
                if ($move->attack_amount > 0){
                    $move->attack_amount += $can_raise;
                    toLog("incremented attack amount by $can_raise from $move->attack_start to $move->attack_end");
                }
            }
        }else{
            $move->deploy_max = $move->deploy_min;
        }
        toLogX("armies left in region $move->attack_start : {$map->armies_left_region[$move->deploy_loc]}");
    }
    
    // if we still have any armies left, dump them somewhere useful
    if($map->armies_left_deploy > 0){
        $any_region = -1;
        $strongest_enemy = 1;
        // lets find the strongest enemy province we border
        foreach ($map->my_regions as $region_id){
            $enemy_adyacent = $map->has_adyacent($region_id, $map->player_two);
            if(count($enemy_adyacent) < 1){continue;}
            $strongest_adyacent = $map->strongest_province($enemy_adyacent);
            // but not if they are too strong...
            if($map->regions[$strongest_adyacent]->armies > $map->regions[$region_id]->armies){continue;}
            if($map->regions[$strongest_adyacent]->armies > $strongest_enemy){
                $strongest_enemy = $map->regions[$strongest_adyacent]->armies;
                $any_region = $region_id;
            }
        }
        // if none, just dump armies on my biggest stack
        if($any_region < 0){
            $any_region = $map->strongest_province($map->my_regions);            
        }
        $goodmoves[] = new CMove(1, 0, $map->armies_left_deploy, $any_region, 0, 0, 0, 0);
        $map->armies_left_region[$any_region] += $map->armies_left_deploy;
        toLog("emergency deploy: $map->armies_left_deploy to $any_region");
    }
    
    // if any move is the only one leaving a given region, put all into it to avoid splitting our stack
    foreach ($goodmoves as $move){if(($move->attack_start > 0) && ($map->armies_left_region[$move->attack_start] > 1)){
        $other_moves = 0;
        foreach ($goodmoves as $a_move){
            if($move->attack_start == $a_move->attack_start){
                $other_moves++;
            }
        }
        toLogX("$other_moves other moves from region $move->attack_start");
        if($other_moves < 2){
            $move->attack_amount += ($map->armies_left_region[$move->attack_start]);
            toLog("increased attack amount of the only attack leaving {$map->region_names[$move->attack_start]}");
        }
        }
        if($move->attack_amount > $biggest_attack){
            $biggest_attack = $move->attack_amount;
        }
    }
    
    // if we have a lot more armies than the defender in any given attack with a big stack, attack early
    foreach ($goodmoves as $move){
        if( ($move->attack_amount > 15) && (($map->regions[$move->attack_end]->armies * 2) < ($move->attack_amount)) ){
            if($move->attack_amount == $biggest_attack){                
                $move->delay = 0;
            }else{
                $move->delay = 1;
            }
            toLog("prioritized attack from {$move->attack_start} to {$move->attack_end} because of good odds" 
                    . "({$move->attack_amount} vs {$map->regions[$move->attack_end]->armies})");
        }
    }
    
    usort($goodmoves, "delay");
    $map->final_moves = $goodmoves;
    $map->proposed_moves = array();
}

function deployToString(){
    $string = "";
    $count = 0;
    global $map;
    $deploy_orders = array();
    foreach ($map->final_moves as $move){
        toLog("final move: P: $move->priority D: $move->delay DEP: $move->deploy_max L: {$map->region_names[$move->deploy_loc]}" . 
                " ATT: $move->attack_amount -> {$map->region_names[$move->attack_end]}");
        if ($move->deploy_max == 0){
            continue;
        }
        if (!array_key_exists($move->deploy_loc, $deploy_orders)){
            $deploy_orders[$move->deploy_loc] = 0;
        }
        $deploy_orders[$move->deploy_loc] += $move->deploy_max;
    }
    foreach($deploy_orders as $region => $amount){
        if($count > 0){
            $string .= ", ";
        }
        $string .= "$map->player_one place_armies $region $amount";
        $count++;
    }
    return $string;
}

function attackToString(){
    $string = "";
    $count = 0;
    global $map;
    $duplicates = array();
    foreach ($map->final_moves as $key => $move){
        if ($move->attack_amount < 1){
            continue;
        }
        if(in_array($key, $duplicates)){
            continue;
        }
        foreach ($map->final_moves as $key_b => $move_b){
            if($key == $key_b){continue;}
            if ( ($move_b->attack_start == $move->attack_start) && 
                    ($move_b->attack_end == $move->attack_end) && 
                    ($move_b->attack_amount > 0) ){
                $move->attack_amount += $move_b->attack_amount;
                $duplicates[] = $key_b;
            }
        }
        if($count > 0){
            $string .= ", ";
        }
        // we can't predict enemy deployments if we attacked him the turn before
        $map->deploy_last[$move->attack_end] = -1;
        
        $string .= "$map->player_one attack/transfer $move->attack_start $move->attack_end $move->attack_amount";
        $count++;
    }
    if ($string == ""){
        $string = "No moves";
    }
    return $string;
}

function reqArmiesAttackCrit($def){
    if ($def == 2){
        return 3;
    }
    if($def < 6){
        $reqarmies = ($def*1.3);
    }else if($def < 20){
        $reqarmies = ($def*1.2);
    }else{        
        $reqarmies = ($def*1.15);
    }
    return ceil($reqarmies);
}

function reqArmiesAttack($def){
    if ($def == 2){
        return 4;
    }
    if($def < 6){
        $reqarmies = ($def*1.6);
    }else if($def < 20){
        $reqarmies = ($def*1.4);
    }else{        
        $reqarmies = ($def*1.2);
    }
    return ceil($reqarmies);
}

function reqArmiesDefend($att){
    if($att < 10){
        return $att;
    }else{
        return round(($att*95)/100);
    }
}

function nonZero($int){
    if($int < 0){
        return 0;
    }else{
        return $int;
    }
}

function priority($a, $b){
    return ($a->priority < $b->priority);
}

function regionByArmies($a, $b){
    global $map;
    return $map->regions[$a]->armies < $map->regions[$b]->armies;
}

function delay($a, $b){
    return ($a->delay > $b->delay);
}

function attStr($a, $b){
    return ($a->attack_amount < $b->attack_amount);
}

function toLog($msg){
    global $round, $log, $line;
    $msg = $round . "  " . $msg;
    $line++;
    if ($log){
        $f = fopen( $log , "a");
        fwrite($f, $msg);
        fwrite($f, "\n");
        fclose($f);
    }
}

function toLogX($msg){
    global $verbose;
    if($verbose){
        toLog($msg);
    }
}

function toLogDiscardMove($msg, $move){
    toLog("skipped move deploy $move->deploy_min - $move->deploy_max to $move->deploy_loc " .
        "and attack with $move->attack_amount to $move->attack_end ($msg) priority: $move->priority, delay: $move->delay");
}