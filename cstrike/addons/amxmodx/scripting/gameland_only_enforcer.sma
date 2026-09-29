#include <amxmodx>
#include <reapi>

#define PLUGIN  "GAMELAND AllClient Permanent Enforcer"
#define VERSION "4.0"
#define AUTHOR  "GAMELAND"

#define ALLCLIENT_HANDSHAKE_SECRET "GL_SECRET_HANDSHAKE_KEY_2026_NCL_GAMELAND"
#define ALLCLIENT_LEGACY_SIGNATURE "GL_PERMANENT_VERIFIED_ALLCLIENT_2026"

new bool:g_isVerified[33];
new g_szPlayerNonce[33][17];
new g_szExpectedHash[33][65];

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR);
    server_print("[GAMELAND] ================================================================");
    server_print("[GAMELAND] AllClient Dynamic Challenge-Response Enforcer v4.0 LOADED!");
    server_print("[GAMELAND] Zero-Trust Cryptographic Engine Verification Active.");
    server_print("[GAMELAND] ================================================================");
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

    // Generate random 16-hex nonce for dynamic challenge
    GenerateRandomNonce(g_szPlayerNonce[id], 16);

    // Compute expected SHA256 response: hash_string("<nonce>:<secret>", Hash_Sha256)
    new szRaw[128];
    formatex(szRaw, charsmax(szRaw), "%s:%s", g_szPlayerNonce[id], ALLCLIENT_HANDSHAKE_SECRET);
    hash_string(szRaw, Hash_Sha256, g_szExpectedHash[id], charsmax(g_szExpectedHash[]));

    remove_task(id);
    set_task(0.25, "TaskQueryClientChallenge", id);
    set_task(3.5, "EnforceTimeoutDrop", id);
}

public TaskQueryClientChallenge(id)
{
    if (is_user_connected(id) && !is_user_bot(id) && !is_user_hltv(id))
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

    // Check dynamic SHA256 challenge response OR legacy signature
    if (equal(value, g_szExpectedHash[id]) || equal(value, ALLCLIENT_LEGACY_SIGNATURE))
    {
        g_isVerified[id] = true;
        remove_task(id);

        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED] Player '%s' successfully authenticated via Dynamic Cryptographic Handshake.", name);
    }
    else
    {
        ExecuteDrop(id, "Dynamic Challenge Failed (Generic NextClient/Steam/Non-Steam)");
    }
}

public EnforceTimeoutDrop(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    if (g_isVerified[id])
        return;

    ExecuteDrop(id, "Timeout / Adam-e Ehraz-e Hoviat (Unauthorized Client)");
}

stock ExecuteDrop(id, const reason[])
{
    new name[32], ip[32], userid = get_user_userid(id);
    get_user_name(id, name, charsmax(name));
    get_user_ip(id, ip, charsmax(ip), 1);

    server_print("[GAMELAND] [KICK] Unauthorized client '%s' (%s, #%d) dropped! Reason: %s", name, ip, userid, reason);
    rh_drop_client(id, "^n[GAMELAND] Faghat AllClient Ekhtesasi mojaz ast!^nDownload: gameland.cam");
}
