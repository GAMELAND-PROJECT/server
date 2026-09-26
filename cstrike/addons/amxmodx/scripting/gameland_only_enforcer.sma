#include <amxmodx>
#include <next_client_api>

#define PLUGIN  "GAMELAND AllClient Exclusive Enforcer"
#define VERSION "2.0"
#define AUTHOR  "GAMELAND"

#define ALLCLIENT_TOKEN "GAMELAND_ALLCLIENT_PRO_2026"

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR);
    server_print("[GAMELAND] AllClient Exclusive Enforcer v2.0 loaded! Strictly enforcing official GAMELAND AllClient.");
}

public client_putinserver(id)
{
    if (is_user_bot(id) || is_user_hltv(id))
        return;

    // Allow 1.2 seconds for network packet handshake
    remove_task(id);
    set_task(1.2, "CheckAllClientAuth", id);
}

public client_disconnected(id)
{
    remove_task(id);
}

public CheckAllClientAuth(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    // Layer 1: Check NextClient protocol handshake
    if (!ncl_is_using_nextclient(id))
    {
        ExecuteKick(id, "Gheyr-e NextClient (Standard/Vanilla CS)");
        return;
    }

    // Layer 2: Check GAMELAND AllClient exclusive cryptographic token in UserInfo
    new clientToken[64];
    get_user_info(id, "*allclient", clientToken, charsmax(clientToken));

    if (!equal(clientToken, ALLCLIENT_TOKEN))
    {
        ExecuteKick(id, "NextClient Omumi / Motefaraghe (Generic NextClient)");
        return;
    }

    // Client is 100% verified GAMELAND AllClient
    new name[32];
    get_user_name(id, name, charsmax(name));
    server_print("[GAMELAND] Client VERIFIED for '%s' -> GAMELAND AllClient Pro", name);
}

stock ExecuteKick(id, const reason[])
{
    new userid = get_user_userid(id);
    new name[32], ip[32];
    get_user_name(id, name, charsmax(name));
    get_user_ip(id, ip, charsmax(ip), 1);

    server_print("[GAMELAND] REJECTED unauthorized client for '%s' (%s, #%d) - Reason: %s", name, ip, userid, reason);
    server_cmd("kick #%d ^"Kicked: Faghat AllClient Ekhtesasi GAMELAND mojaz ast! Download: gameland.cam^"", userid);
}
