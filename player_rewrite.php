<?php

$GLOBALS["ENGINE_ROOT"] = __DIR__ . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $GLOBALS["ENGINE_ROOT"];
$enginePath             = $GLOBALS["ENGINE_ROOT"];

require_once $enginePath . "conf/conf.php";
require_once $enginePath . "lib/logger.php";
require_once $enginePath . "lib/model_dynmodel.php";
require_once $enginePath . "lib/{$GLOBALS["DBDRIVER"]}.class.php";
$GLOBALS["db"] = new sql();
require_once $enginePath . "prompts/command_prompt.php";
require_once $enginePath . "lib/chat_helper_functions.php";
require_once $enginePath . "lib/data_functions.php";
require_once $enginePath . "lib/rolemaster_helpers.php";

// New profile system
require_once $enginePath . "lib/core/api_badge.class.php";
require_once $enginePath . "lib/core/llm_connector.class.php";
require_once $enginePath . "lib/core/tts_connector.class.php";
require_once $enginePath . "lib/core/npc_master.class.php";
require_once $enginePath . "lib/core/core_profiles.class.php";

SaveOriginalHerikaName();
$GLOBALS["HERIKA_NAME"] = "(actor)";

// Initialize function parameters before requiring functions.php
$GLOBALS["FUNCTION_PARM_INSPECT"] = [];
$GLOBALS["FUNCTION_PARM_MOVETO"]  = [];
$GLOBALS["F_NAMES"]               = [];

require $enginePath . "functions/functions.php";

$connector            = new LLMConnector();
$currentConnectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_PLAYER"]);
$connectionHandler    = $connector->getConnector($currentConnectorData);

$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
$GLOBALS["CURRENT_CONNECTOR"]                = $currentConnectorData["driver"];

$connector->setOldGlobals($currentConnectorData);

// Make functions.php data global

$GLOBALS["FUNCTIONS_ARE_ENABLED"] = false;

// Some functions need this setted */
$res                       = $GLOBALS["db"]->fetchAll("select max(gamets)+1 as gamets,max(ts)+1 as ts  from eventlog order by gamets desc limit 1 offset 0");
$GLOBALS["gameRequest"]    = ["inputtext"];
$GLOBALS["gameRequest"][2] = $res[0]["gamets"] + 1;

$GLOBALS["CHIM_NO_EXAMPLES"] = true; // When no assistant entry in history, will try ti provide a bogus example.

if (! $_GET["speech"]) {
    if ($argv[1]) {
        $_GET["speech"] = $argv[1];
    }
}
$targetNpc = isset($argv[2]) && $argv[2] !== '' ? trim($argv[2]) : '';

if (! isset($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])) {
    error_log("Choose a LLM model and connector. Used connector: '{$GLOBALS["CORE_CONNECTOR_DIRECTOR"]}'", S_LOG_CRITICAL);

} else {

    error_log("Using {$GLOBALS["CURRENT_CONNECTOR"]} <{$argv[1]}>");

    $contextDataHistoric = DataLastDataExpandedFor("", -15);
    $contextDataHistoric = array_merge([["role" => "user", "content" => "# HISTORIC DIALOGUE AND EVENTS IN CHRONOLOGICAL ORDER"]], $contextDataHistoric);

    $contextDataWorld = DataLastInfoFor("", -2, $addNPCDescriptions = true, $excludeBusy = true);
    $contextDataFull  = array_merge($contextDataWorld??[], $contextDataHistoric??[]);
    $historyData      = "";
    foreach ($contextDataFull as $element) {
        $historyData .= trim("{$element["content"]}") . PHP_EOL . PHP_EOL;
    }

    // Load player data from core_player table
    $playerAppearance = '';
    $playerSpeechStyle = '';
    try {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        $player = new Player();
        $playerAppearance = $player->get('appearance');
        $playerSpeechStyle = $player->get('speech_style');
    } catch (Exception $e) {
        error_log("Could not load player data from core_player: " . $e->getMessage());
    }
    if (empty($playerSpeechStyle) && isset($GLOBALS["PLAYER_SPEECH_STYLE"]) && !empty($GLOBALS["PLAYER_SPEECH_STYLE"])) {
        $playerSpeechStyle = $GLOBALS["PLAYER_SPEECH_STYLE"];
    }

    // Build player character context block
    $playerContext = "";
    if (!empty($playerAppearance)) {
        $bio = strtr($playerAppearance, ["#PLAYER_NAME#" => $GLOBALS["PLAYER_NAME"]]);
        $playerContext .= "Character Background: " . trim($bio) . "\n\n";
    }
    if (!empty($playerSpeechStyle)) {
        $playerContext .= "Speech Style: " . trim($playerSpeechStyle) . "\n\n";
    }

    // Build system message: simple identity + PROMPT_HEAD as roleplay ruleset
    $promptHead = '';
    if (!empty($GLOBALS["PROMPT_HEAD"])) {
        $promptHead = strtr($GLOBALS["PROMPT_HEAD"], ["#HERIKA_NAME#" => $GLOBALS["PLAYER_NAME"]]);
    }

    $npcLine = (!empty($targetNpc) && $targetNpc !== '(actor)') ? " You are currently speaking to {$targetNpc}." : '';
    $systemContent = "You are roleplaying as {$GLOBALS["PLAYER_NAME"]}.{$npcLine}";
    if (!empty($promptHead)) {
        $systemContent .= "\n\n" . trim($promptHead);
    }
    if (!empty($playerContext)) {
        $systemContent .= "\n\n# Character Context\n" . $playerContext;
    }

    // Build instruction
    if (!$_GET["speech"] || $_GET["speech"] === "**") {
        $instruction = "Write dialogue for {$GLOBALS["PLAYER_NAME"]}.";
    } else {
        $raw_speech = $_GET["speech"] ?? "";
        $speech = trim(preg_replace('/^\*+\s*/', '', $raw_speech));

        // Handle ## syntax allows to replay or store/recall instructions
        // eg. '## I want to negotiate'   <- '##' will prompt the rewriter with the same prompt allowing player to have intent driven conversations automatically
        // Syntax options:
        //   ## <text>         - Save new text as primary request
        //   ##                - Recall saved primary request
        //   ## #n             - Recall primary request with length constraint #n
        //   ##_name <text>    - Save text as named request
        //   ##_name           - Save current primary as named request
        //   ##name            - Recall named request
        //   ##name #n         - Recall named request with length constraint #n
        //   ##-name           - Delete named request
        if (strpos($speech, '##') === 0) {
            $remainder = trim(substr($speech, 2));

            // Check for named speech operations (##_name, ##-name, ##name)
            if (preg_match('/^(_|-)(\w+)(?:\s+(.+))?$/', $remainder, $matches)) {
                $operation = $matches[1]; // _ for save, - for delete
                $key_name = trim($matches[2]);
                $text = isset($matches[3]) ? trim($matches[3]) : '';
                $storage_key = 'rewrite_named_' . $key_name;

                if ($operation === '_') {
                    // Save operation: ##_name or ##_name <text>
                    if (!empty($text)) {
                        // ##_name <text>: save text as named key, set as prime, use as $speech
                        $player->set($storage_key, $text);
                        $player->set('last_rewrite_request', $text);
                        $speech = $text;
                    } else {
                        // ##_name: save current prime as named key, use prime as $speech
                        $current_prime = $player->get('last_rewrite_request') ?? '';
                        $player->set($storage_key, $current_prime);
                        $speech = $current_prime;
                    }
                } elseif ($operation === '-') {
                    // Delete operation: ##-name
                    // in-game this is kindof jank as it triggers a dialogue turn
                    $player->delete($storage_key);
                    $speech = $player->get('last_rewrite_request') ?? '';
                }
            } elseif (preg_match('/^(\w+)(?:\s+(#\d+))?$/', $remainder, $matches)) {
                // Recall operation: ##name or ##name #2
                $key_name = trim($matches[1]);
                $length_constraint = isset($matches[2]) ? $matches[2] : '';
                $storage_key = 'rewrite_named_' . $key_name;
                $recalled = $player->get($storage_key);

                if ($recalled !== null && $recalled !== '') {
                    // Key exists: use it
                    $speech = $recalled;
                    // If length constraint provided, strip existing #n and add new one
                    if ($length_constraint) {
                        $speech = preg_replace('/#\d+/', '', $speech);
                        $speech = trim($speech) . ' ' . $length_constraint;
                    }
                    $player->set('last_rewrite_request', $speech);
                } else {
                    // Key doesn't exist: use current prime (user likely forgot _ when saving)
                    $speech = $player->get('last_rewrite_request') ?? '';
                }
            } elseif (preg_match('/^(#\d+)$/', $remainder, $matches)) {
                // ## #n pattern: retrieve saved instruction and replace length constraint
                $speech = preg_replace('/#\d+/', '', $player->get('last_rewrite_request') ?? '');

                if ($matches[1] !== '#0') {
                    $speech = trim($speech) . ' ' . $matches[1];
                } else {
                    $speech = trim($speech);
                }
                $player->set('last_rewrite_request', $speech);
            } elseif ($remainder === '') {
                // ## alone: retrieve stored instruction
                $speech = $player->get('last_rewrite_request') ?? '';
            } else {
                // ## <instruction>: save new instruction
                $speech = $remainder;
                $player->set('last_rewrite_request', $speech);
            }

            // Handle the #n sentence constraint in PHP for better reliability
            $length_instruction = " Pay attention to comments between brackets, that can guide you in length and verbosity.";
            if (preg_match('/#(\d+)/', $speech, $matches)) {
                $num = $matches[1];
                $length_instruction = "\n\nSTRICT REQUIREMENT: Your response must be exactly {$num} sentence(s) long.";
                // Clean the #tag out so it doesn't confuse the narrative engine
                $speech = trim(str_replace($matches[0], '', $speech));
            }

            $instruction = "### TASK: DIALOGUE GENERATION
<input_directive>{$speech}</input_directive>
Transform the provided <input_directive> into dialogue. You are the creative voice of {$GLOBALS["PLAYER_NAME"]}.

### CORE DIRECTIVES
1. **Intent Manifestation:** 
   - If the directive is literal (e.g. a specific line of dialogue), rewrite the dialogue synthesizing your Character Context.
   - If the directive is abstract or strategic (e.g. a goal, a mood, a scene, or a type of argument), you are responsible for creatively inventing the next dialogue to achieve that intent.
2. **Contextual Awareness:** Use the provided Contextual data to ensure your response is the next logical progression of the story, but never repeat text from recent history.

### OUTPUT CONSTRAINT
{$length_instruction}";

        } else {
            // Handle the #n sentence constraint in PHP for better reliability
            $length_instruction = " Pay attention to comments between brackets, that can guide you in length and verbosity.";
            if (preg_match('/#(\d+)/', $speech, $matches)) {
                $num = $matches[1];
                $length_instruction = "\n\nSTRICT REQUIREMENT: Your response must be exactly {$num} sentence(s) long.";
                // Clean the #tag out so it doesn't confuse the narrative engine
                $speech = trim(str_replace($matches[0], '', $speech));
            }

            $instruction = "Rewrite dialogue for {$GLOBALS["PLAYER_NAME"]}, using this text as source \"{$GLOBALS["PLAYER_NAME"]}:$speech\"." . $length_instruction;
        }
    }

    $prompt[] = ['role' => 'system', 'content' => $systemContent];
    $prompt[] = ['role' => 'user',   'content' => "# Contextual data\n$historyData"];
    $prompt[] = ['role' => 'user',   'content' => $instruction];
    $prompt[] = ['role' => 'user',   'content' => "Just output dialogue"];
    $customParm["MAX_TOKENS"] = 4000;

    $buffer = $connectionHandler->fast_request($prompt, ["MAX_TOKENS" => 4000]);
    $buffer = str_replace("{$GLOBALS["PLAYER_NAME"]}:}", "", $buffer);
    echo trim($buffer) . PHP_EOL;

}

Logger::info("Successfully logged instruction command to responselog");
