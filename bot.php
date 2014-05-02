#!/usr/bin/php
<?php
//include __main__;
//  
//  turn 6: wtf
// http://theaigames.com/competitions/warlight-ai-challenge/games/536119ee4b5ab21cbdadc0d6
//
//

include_once 'classes/map.php';
include_once 'classes/moveReg.php';
include_once 'classes/strat.php';

$run = true;
$map = new CMap();
$mreg = new CRegister();
$strat = new CStrat($map, $mreg);
$log = false;
$round = 0;
$loadgame = array();
$loading = 0;
$verbose = 0;

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
    $starts = explode(" ", substr($inputStr, strlen("pick_starting_regions 2000 ")));
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
        $value = 1000 - ($bonusval*10);
        $value += count($map->regions[$start]->connections);
        $value += count($map->bonuses_bordering_prov($start));
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
                $value -= 11;
            }
        }
    }
    return implode(" ", $orders);
}

function updateMap($inputStr){
    // example query is: "update_map 1 player1 2 2 player1 4 3 neutral 2 4 player2 5"
    global $round;
    global $map;
    $round++;
    toLog("\n*****ROUND $round*****\n");
    // new round, enemy moves get archived
    $map->enemy_deploy[$round - 1] = $map->deploy_last;
    // default deploy stats are set to "no info"
    $map->deploy_last = array_fill(1, count($map->regions), -1);
    $map->sos_call = array_fill(1, count($map->regions), -1);
    $map->blocked_regions = array_fill(1, count($map->regions), -1);
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
                $pos = array_search($bonus->id, $map->guessed_bonuses);
                unset($map->guessed_bonuses[$pos]);
                foreach ($bonus->regions as $region){
                    if(in_array($region->id, $map->guessed_regions)){
                        $pos = array_search($region->id, $map->guessed_regions);
                        unset($map->guessed_regions[$pos]);
                        if(($region->owner == $map->player_two) && ($region->visible == 0)){
                            $region->owner = "unknown";
                        }
                    }
                }
                if(($map->income_two - $bonus->income) >= 5){
                    $map->income_two -= $bonus->income;
                }
                $map->bonuses_broken_last_turn[] = $bonus->id;                
                toLog("guessed bonus {$map->bonus_names[$bonus->id]} not owned by enemy");
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
            toLogX("region {$map->region_names[$region->id]} owned by {$region->owner}");
        }
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
    global $strat, $round;
    $strat->think($round);
    $state = $strat->get_state();
    
    if($state == 1){
        toLog("opening move");
        $state = openingMove();
    }
    if($state != 1){
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
    global $map;
    $adyacent_to_enemy = array();    
    $smallbonus = $map->smallestbonus;
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
        case 0: return 0;
        case 1:{
            $map->proposed_moves[] = new CMove(10, 5, 5, $adyacent_to_enemy[0], 0, 0, 0, 1);
            break;
        }
        default:{
            $weight_attack_rand = 10;
            $weight_attack_east = 10;
            $weight_defend_both = 10;
            $weight_defend_one = 10;
            $choice_raw[0] = rand(0, $weight_attack_rand);
            $choice_raw[1] = rand(0, $weight_attack_east);
            $choice_raw[2] = rand(0, $weight_defend_both);
            $choice_raw[3] = rand(0, $weight_defend_one);            
            $choice = array_keys($choice_raw, max($choice_raw));
            toLog("opening choice: $choice[0]");
            
            switch ($choice[0]){
                case 0:{
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
                    $attack = true;
                    break;
                }
                case 3:{
                    shuffle($adyacent_to_enemy);
                    $start_id = $adyacent_to_enemy[0];
                    $attack = true;
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

function blockStrategics(){
    global $map;
    // no need to waste time defending bonuses too much if we have a big edge
    if($map->income_one > ($map->income_two * 3)){return 0;}
    
    $bonuses_to_check = $map->my_bonuses;
    if($map->bonus_taking_now > 0){
        $bonuses_to_check[] = $map->bonus_taking_now;
    }
    foreach ($bonuses_to_check as $bonus_id){
        $adyacent_regions = $map->prov_ady_to_bonus($bonus_id);
        foreach ($adyacent_regions as $region_id){
            // block everything except break bonus
            $priority = 8;
            if($map->regions[$region_id]->owner != $map->player_one){continue;}
            
            $adyacent_enemies = $map->has_adyacent($region_id, $map->player_two);
            if(count($adyacent_enemies) < 1){continue;}
            
            $strongest_enemy = $map->strongest_province($adyacent_enemies);
            $my_armies = $map->regions[$region_id]->armies;
            $his_armies = $map->regions[$strongest_enemy]->armies;
            
            // if no stack, no need to block
            if($his_armies < $map->income_one){continue;}
            if($my_armies < $map->income_one){continue;}
            
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
    global $map;
    $adyacent_armies = 1;
    $defendables = array();
    foreach ($map->my_bonuses as $bonusid){
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
            $reinforce = $potential_enemies - $defenders;
            // take into account other provinces defending against this threat
            if(key_exists($threat_province, $map->threats_to_bonus)){
                $reinforce -= $map->threats_to_bonus[$threat_province];
            }else{
                $map->threats_to_bonus[$threat_province] = 0;
            }
            //
            if ($reinforce > $map->armies_left_deploy){
                $reinforce = $map->armies_left_deploy;
            }elseif($reinforce < 1){continue;}
            // to defend a bonus we set up one order per army, to make sure all available armies are used here
            $priority = 9;
            for($i = 0; $i < $reinforce; $i++){
                $defendables[] = new CMove($priority ,1 , 1, $region->id, 0, 0, 0, 0);                
            }
            $map->block_region($region->id, $priority);
            $map->send_sos($region->id, $priority);
            $map->threats_to_bonus[$threat_province] += ( $reinforce + $defenders );
            toLog("defense move proposed: deploy {$reinforce} at {$region->id}");
        }
    }
    shuffle($defendables);
    $map->proposed_moves = array_merge($map->proposed_moves, $defendables);
}

function breakBonus(){
    global $map, $strat;
    $targets = array();      // holds moves
    //$priority = 8;
    $priority = $strat->get_strat("break");
    toLogX("break priority = $priority");
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
                    if ($plusminus < $att - $def ){
                        $best_prov_b = $my_province;
                        $best_target = $his_province;
                        $plusminus = $att - $def;
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
            }
        }
        if ($max_armies < $req){
            toLog("not enough armies to break {$map->bonus_names[$bonusid]} at {$map->region_names[$best_target]} from {$map->region_names[$best_prov_b]} ({$max_armies} vs {$defenders}, required {$req})");
            $map->send_sos($best_prov_b, $priority);
            continue;
        }
        $req_deploy = nonZero(($req - ($available_armies)) + 1);
        $targets[] = new CMove($priority ,$req_deploy , $req_deploy, $best_prov_b, $req, $best_prov_b , $best_target, 10);
        for($i = 0; $i < ($map->income_one - $req_deploy); $i++){
            $targets[] = new CMove($priority - 2 ,1 , 1, $best_prov_b, 0, 0, 0, 0);
        }
        toLog("break proposed priority $priority: deploy $req_deploy - {$map->income_one} at {$map->region_names[$best_prov_b]} and attack {$map->region_names[$best_target]}");
    }
    // 
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
            
            toLogX("defense at {$map->region_names[$my_province]}: ($max_his attackers vs max $my_max_defense defenders)");
            
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
                $armies_needed = ($max_his*9) / 10;
                $max_deploy = intval($armies_needed - $my_armies);
                $priority = 7;
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
            usort($adyacent_enemies, "regionByArmies");
            foreach ($adyacent_enemies as $target_id){
                $strongest_mine_adyacent = $map->strongest_province($map->has_adyacent($target_id, $map->player_one));
                if($strongest_mine_adyacent != $region_id){
                    continue;
                }
                $priority = 6;
                $his_armies = $map->regions[$target_id]->armies;
                $my_armies = $map->regions[$region_id]->armies - 1;
                $predicted_deploy = $strat->predict_deploy($target_id);
                toLogX("predicted $predicted_deploy");
                // normal attack                
                toLogX("his armies : $his_armies predicted deploy : $predicted_deploy");
                $attack_str = reqArmiesAttack($his_armies + $predicted_deploy);
                toLogX("attack str: $attack_str ");
                $delay = 9;
                $need_deploy = nonZero(($attack_str + 1 ) - $my_armies);
                if ($need_deploy > $map->income_one){
                    $my_armies += 1;
                    $priority = 6;
                    $potential_attackers = $his_armies + $predicted_deploy - 1;
                    $necessary_defenders = reqArmiesDefend($potential_attackers);
                    $need_deploy = nonZero($necessary_defenders - $my_armies);
                    if ($need_deploy > $map->income_one){
                        $need_deploy = 0;
                        toLogX("too weak to defend position at {$map->region_names[$region_id]}");
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
                    $destroyables[] = new CMove($priority, $need_deploy, $map->income_one, $region_id, 
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
            $priority = 7;
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
    
    // no exploration during all out war
    $state = $strat->get_state();
    if($state == 3){return 0;}
    
    //if we have the income edge, sit on it
    if($map->income_one > $map->income_two){return 0;}
    
    $explorables = array();
    foreach($map->bonuses as $bonus){
        $attitude = $strat->get_bonus_attitude($bonus->id);
        if($attitude != 2){continue;}
        
        $my_provinces = $map->prov_in_bonus($bonus->id, $map->player_one);
        $priority = 3;
        if(count($my_provinces) == 0){
            $priority = 1;        
        }
        
        $neutrals = $map->prov_in_bonus($bonus->id, "neutral");
        $target_id = -1;
        $most_value = 0;
        
        // find the best neutral to explore
        foreach ($neutrals as $neutral_id){
            //if it doesnt have adyacent unknown or enemy, it doesnt help us
            $unknown_adyacent = $map->has_adyacent_inbonus($neutral_id, "unknown", $bonus->id);
            $enemy_adyacent = $map->has_adyacent_inbonus($neutral_id, $map->player_two, $bonus->id);
            if ((count($unknown_adyacent) == 0) && (count($enemy_adyacent) == 0)){continue;}
                
            $my_adyacent = $map->has_adyacent($neutral_id, $map->player_one);
            $my_blocked = $map->get_blocked($priority);
            toLogX("blocked priority $priority : " . implode(" ", $my_blocked));
            $my_non_blocked = array_diff($my_adyacent, $my_blocked);
            toLogX("non blocked priority $priority : " . implode(" ", $my_non_blocked));
            if (count($my_non_blocked) < 1){ continue; }
            $my_adyacent_armies = $map->regions[$map->strongest_province($my_non_blocked)]->armies;
            $value = $my_adyacent_armies + count($map->regions[$neutral_id]->connections);
            
            $other_bonuses = count($map->bonuses_bordering_prov($neutral_id));
            $value += ($other_bonuses * 3);
            toLogX("bonuses adyacent to {$map->region_names[$neutral_id]}: " 
            . implode(" ", $map->bonuses_bordering_prov($neutral_id)));
            
            if ($value > $most_value){
                $most_value = $value;
                $target_id = $neutral_id;
            }
        }
        if ($target_id > -1){
            $my_adyacent = $map->has_adyacent($target_id, $map->player_one);
            $my_blocked = $map->get_blocked($priority);
            toLogX("blocked priority $priority : " . implode(" ", $my_blocked));
            $my_non_blocked = array_diff($my_adyacent, $my_blocked);
            toLogX("non blocked priority $priority : " . implode(" ", $my_non_blocked));
            $best_province = $map->strongest_province($my_non_blocked);
            // if no province to attack from, skip
            if($best_province < 1){continue;}
            // one again we bail if we have enemies nearby
            if ($map->has_adyacent($best_province, $map->player_two)){continue;}
            
            $armies_attack = reqArmiesAttack($map->regions[$target_id]->armies);
            $armies_deploy = nonZero($armies_attack - $map->regions[$best_province]->armies + 1);
            $explorables[] = new CMove($priority, $armies_deploy, $map->income_one, $best_province, $armies_attack, 
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
    foreach ($map->my_regions as $region_id){
        
        $armies_to_move = $map->regions[$region_id]->armies - 1;
        if ($armies_to_move < 1){continue;}
        
        // if we're next to an enemy skip
        if ($map->has_adyacent($region_id, $map->player_two)){continue;}   
        
        // if we're next to a neutral inside a bonus we want to take
        if ($map->has_adyacent($region_id, "neutral")){
            $bonus = $map->regions[$region_id]->bonus;
            $attitude = $strat->get_bonus_attitude($bonus);
            if($attitude == 5){continue;}    
        }    
        
        $target = -1;
        // if we know enemy regions
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
        if($target < 0){ continue;}
        // if the path goes through neutrals, make sure we have troops to do that
        if($map->regions[$target]->owner == "neutral"){
            if($armies_to_move < 4){continue;}
        }
        
        $priority = 1;
        $delay = 3;
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
        toLogX("proposed move: P: $move->priority D: $move->deploy_max L: {$map->region_names[$move->deploy_loc]}" . 
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
        toLog("final move: P: $move->priority D: $move->deploy_max L: {$map->region_names[$move->deploy_loc]}" . 
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
    foreach ($map->final_moves as $move){
        if ($move->attack_amount < 1){
            continue;
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
        $reqarmies = ($def*1.1);
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
        return round(($att*9)/10);
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
    global $log;
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