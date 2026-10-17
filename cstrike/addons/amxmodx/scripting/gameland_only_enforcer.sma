#include <amxmodx>
#include <reapi>
#include <next_client_api>

#define PLUGIN  "GAMELAND AllClient Permanent Enforcer"
#define VERSION "5.0"
#define AUTHOR  "GAMELAND"

#define ALLCLIENT_HANDSHAKE_SECRET "GL_SECRET_HANDSHAKE_KEY_2026_NCL_GAMELAND"
#define ALLCLIENT_LEGACY_SIGNATURE "GL_PERMANENT_VERIFIED_ALLCLIENT_2026"
#define ALLCLIENT_USERINFO_KEY     "_gltoken"
#define ALLCLIENT_USERINFO_VAL     "GAMELAND_ALLCLIENT_PRO_2026"

new bool:g_isVerified[33];
new g_szPlayerNonce[33][17];
new g_szExpectedHash[33][65];

new g_pCvarEnforce;
new g_pCvarTimeout;
new g_pCvarDebug;

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR);

    g_pCvarEnforce = register_cvar("gl_enforce_allclient", "1");
    g_pCvarTimeout = register_cvar("gl_allclient_timeout", "25.0");
    g_pCvarDebug   = register_cvar("gl_allclient_debug", "1");

    server_print("[GAMELAND] ================================================================");
    server_print("[GAMELAND] AllClient Dynamic Multi-Layer Enforcer v5.0 LOADED!");
    server_print("[GAMELAND] Supported: NextClient API + Userinfo Token + Cryptographic Challenge");
    server_print("[GAMELAND] ================================================================");
}

stock LogDebug(const fmt[], any:...)
{
    if (!get_pcvar_num(g_pCvarDebug))
        return;

    new szLog[256];
    vformat(szLog, charsmax(szLog), fmt, 2);
    server_print("[GAMELAND-GUARD] %s", szLog);
}

stock GenerateRandomNonce(output[], len)
{
    static const hexChars[] = "0123456789abcdef";
    for (new i = 0; i < len; i++)
    {
        output[i] = hexChars[random_num(0, 15)];
    }
    output[len] = 0;
}

stock MarkPlayerVerified(id, const method[])
{
    if (!is_user_connected(id) || g_isVerified[id])
        return;

    g_isVerified[id] = true;
    remove_task(id); // Cancel timeout and retry tasks

    new name[32];
    get_user_name(id, name, charsmax(name));
    server_print("[GAMELAND] [VERIFIED] Player '%s' (id: %d) successfully authenticated via [%s].", name, id, method);
    LogDebug("Authentication confirmed for '%s' via %s", name, method);
}

stock bool:CheckUserinfoToken(id)
{
    if (!is_user_connected(id) || g_isVerified[id])
        return g_isVerified[id];

    new szToken[64];
    get_user_info(id, ALLCLIENT_USERINFO_KEY, szToken, charsmax(szToken));

    if (equal(szToken, ALLCLIENT_USERINFO_VAL) || containi(szToken, "GAMELAND") != -1 || containi(szToken, "AllClient") != -1)
    {
        MarkPlayerVerified(id, "Userinfo Token (_gltoken)");
        return true;
    }
    return false;
}

public client_authorized(id)
{
    if (is_user_bot(id) || is_user_hltv(id))
    {
        g_isVerified[id] = true;
        return;
    }

    CheckUserinfoToken(id);
}

public client_infochanged(id)
{
    if (is_user_connected(id) && !g_isVerified[id])
    {
        CheckUserinfoToken(id);
    }
}

public ncl_client_api_ready(id)
{
    if (is_user_connected(id) && !g_isVerified[id])
    {
        MarkPlayerVerified(id, "NextClient Native API Ready");
    }
}

public ncl_hwid_received(id, const hwid[])
{
    if (is_user_connected(id) && !g_isVerified[id] && hwid[0])
    {
        MarkPlayerVerified(id, "NextClient HWID Handshake");
    }
}

public client_putinserver(id)
{
    g_isVerified[id] = false;
    g_szPlayerNonce[id][0] = 0;
    g_szExpectedHash[id][0] = 0;

    if (is_user_bot(id) || is_user_hltv(id))
    {
        g_isVerified[id] = true;
        return;
    }

    // Layer 1: Check instant userinfo token
    if (CheckUserinfoToken(id))
        return;

    // Layer 2: Check NextClient native module state
    if (ncl_is_using_nextclient(id) != NCL_NOT_USING)
    {
        MarkPlayerVerified(id, "NextClient Module Active");
        return;
    }

    // Prepare Layer 3 & 4 (CVAR queries & Cryptographic challenge)
    GenerateRandomNonce(g_szPlayerNonce[id], 16);

    new szRaw[128];
    formatex(szRaw, charsmax(szRaw), "%s:%s", g_szPlayerNonce[id], ALLCLIENT_HANDSHAKE_SECRET);
    hash_string(szRaw, Hash_Sha256, g_szExpectedHash[id], charsmax(g_szExpectedHash[]));

    remove_task(id);

    // Schedule challenge queries with retries
    set_task(0.5, "TaskQueryClientChallenge", id);
    set_task(3.0, "TaskQueryClientChallenge", id);
    set_task(7.0, "TaskQueryClientChallenge", id);

    // Timeout drop task (ample time for slow map precache / download)
    new Float:flTimeout = get_pcvar_float(g_pCvarTimeout);
    if (flTimeout < 10.0) flTimeout = 25.0;
    set_task(flTimeout, "EnforceTimeoutDrop", id);
}

public TaskQueryClientChallenge(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id) || g_isVerified[id])
        return;

    // Re-check userinfo in case it arrived after connection
    if (CheckUserinfoToken(id))
        return;

    // Re-check NextClient API status
    if (ncl_is_using_nextclient(id) != NCL_NOT_USING)
    {
        MarkPlayerVerified(id, "NextClient Module Ready");
        return;
    }

    // Query permanent static signature
    query_client_cvar(id, "gl_allclient_signature", "OnCvarAuthChallengeResult");

    // Query dynamic nonce challenge
    if (g_szPlayerNonce[id][0])
    {
        new szQuery[64];
        formatex(szQuery, charsmax(szQuery), "gl_auth_%s", g_szPlayerNonce[id]);
        query_client_cvar(id, szQuery, "OnCvarAuthChallengeResult");
    }
}

public client_disconnected(id)
{
    g_isVerified[id] = false;
    g_szPlayerNonce[id][0] = 0;
    g_szExpectedHash[id][0] = 0;
    remove_task(id);
}

public OnCvarAuthChallengeResult(id, const cvar[], const value[], const param[])
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    if (g_isVerified[id])
        return;

    // Check dynamic SHA256 challenge response OR legacy signature
    if (equal(value, g_szExpectedHash[id]))
    {
        MarkPlayerVerified(id, "Dynamic SHA256 Challenge");
    }
    else if (equal(value, ALLCLIENT_LEGACY_SIGNATURE))
    {
        MarkPlayerVerified(id, "Permanent AllClient Signature");
    }
    else
    {
        LogDebug("Challenge cvar '%s' returned: '%s' (waiting for valid response or timeout)", cvar, value);
    }
}

public EnforceTimeoutDrop(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    if (!get_pcvar_num(g_pCvarEnforce))
        return;

    if (g_isVerified[id])
        return;

    // Final checks before enforcing drop
    if (CheckUserinfoToken(id))
        return;

    if (ncl_is_using_nextclient(id) != NCL_NOT_USING)
    {
        MarkPlayerVerified(id, "NextClient Module (Final)");
        return;
    }

    ExecuteDrop(id, "Unauthorized Client - Missing GameLand AllClient / NextClient Authentication");
}

stock ExecuteDrop(id, const reason[])
{
    new name[32], ip[32], userid = get_user_userid(id);
    get_user_name(id, name, charsmax(name));
    get_user_ip(id, ip, charsmax(ip), 1);

    server_print("[GAMELAND] [KICK] Unauthorized client '%s' (%s, #%d) dropped! Reason: %s", name, ip, userid, reason);
    rh_drop_client(id, "^n[GAMELAND] Lotfan ba AllClient Ekhtesasi vared shavid.^nDownload: gameland.cam");
}
