/*
 * GameLand CSB C4 Timer Pro
 * Enhanced with Director HUD (Large native-sized clock) placed exactly
 * at the bottom round timer position, with live defuse intelligence for CTs.
 */

#include <amxmodx>
#include <cstrike>

new const PLUGIN[] = "GameLand C4 Timer Pro"
new const VERSION[] = "2.0.0"
new const AUTHOR[]  = "GAMELAND"

#define DEFUSE_NOKIT  10.0
#define DEFUSE_KIT    5.0

#define HIDEHUD_TIMER (1<<4)

new g_pEnabled, g_pBeep
new g_pC4Timer
new g_pPosY
new g_msgHideWeapon

new bool:g_bPlanted = false
new Float:g_flExplodeAt
new g_iSync

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)

    g_pEnabled = register_cvar("gl_c4timer_enabled", "1")
    g_pBeep    = register_cvar("gl_c4timer_beep", "1")
    g_pPosY    = register_cvar("gl_c4timer_pos_y", "0.932")

    g_pC4Timer = get_cvar_pointer("mp_c4timer")
    g_msgHideWeapon = get_user_msgid("HideWeapon")

    g_iSync = CreateHudSyncObj()

    register_logevent("evPlanted", 3, "2=Planted_The_Bomb")
    register_logevent("evDefused", 3, "2=Defused_The_Bomb")
    register_logevent("evTargetBombed", 2, "1=Round_End")

    register_event("HLTV", "evNewRound", "a", "1=0", "2=0")
    register_event("SendAudio", "evBombDefusedAudio", "a", "2=%!MRAD_BOMBDEF")
    register_event("SendAudio", "evTargetBombedAudio", "a", "2=%!MRAD_TARGETBOM")
    register_event("ResetHUD", "evResetHUD", "b")
    register_event("TextMsg", "evRestart", "a", "2=#Game_will_restart_in")
}

public evResetHUD(id)
{
    if (g_bPlanted && get_pcvar_num(g_pEnabled))
        hide_timer(id, true)
    else
        hide_timer(id, false)
}

public client_disconnected(id)
{
    // cleanup
}

public evPlanted()
{
    if (!get_pcvar_num(g_pEnabled))
        return

    new Float:c4timer = g_pC4Timer ? get_pcvar_float(g_pC4Timer) : 35.0
    if (c4timer <= 0.0)
        c4timer = 35.0

    g_bPlanted = true
    g_flExplodeAt = get_gametime() + c4timer

    // Hide native timer for all connected players
    new players[32], num
    get_players(players, num)
    for (new i = 0; i < num; i++)
        hide_timer(players[i], true)

    set_task(0.08, "taskTick", 0, _, _, "b")
}

public evDefused()
{
    stopTimer()
}

public evTargetBombed()
{
    stopTimer()
}

public evBombDefusedAudio()
{
    stopTimer()
}

public evTargetBombedAudio()
{
    stopTimer()
}

public evNewRound()
{
    stopTimer()
}

public evRestart()
{
    stopTimer()
}

stopTimer()
{
    if (!g_bPlanted)
        return

    g_bPlanted = false
    remove_task(0)
    ClearSyncHud(0, g_iSync)

    new players[32], num
    get_players(players, num)
    for (new i = 0; i < num; i++)
        hide_timer(players[i], false)
}

public taskTick()
{
    if (!g_bPlanted)
    {
        remove_task(0)
        return
    }

    new Float:remain = g_flExplodeAt - get_gametime()
    if (remain <= 0.0)
    {
        stopTimer()
        return
    }

    new r, g, b
    fuseColor(remain, r, g, b)

    new players[32], num, pid
    get_players(players, num, "ch")

    new bars[16]
    beepBars(remain, bars, charsmax(bars))

    new Float:posY = get_pcvar_float(g_pPosY)

    for (new i = 0; i < num; i++)
    {
        pid = players[i]

        new note[64]
        note[0] = 0

        if (is_user_alive(pid) && cs_get_user_team(pid) == CS_TEAM_CT)
            defuseNote(pid, remain, note, charsmax(note))

        // Large high-visibility Director HUD exactly in place of the clock:
        set_dhudmessage(r, g, b, -1.0, posY, 0, 0.0, 0.12, 0.0, 0.0)

        if (note[0])
            show_dhudmessage(pid, "[C4] %.1f  %s^n(%s)", remain, bars, note)
        else
            show_dhudmessage(pid, "[C4] %.1f  %s", remain, bars)
    }
}

fuseColor(Float:remain, &r, &g, &b)
{
    if (remain <= 4.0)
    {
        // 4 seconds intense heartbeat flash in bright red
        static flash = 0
        flash = !flash
        if (flash)
        {
            r = 255; g = 20; b = 20;
        }
        else
        {
            r = 160; g = 0; b = 0;
        }
    }
    else if (remain <= 12.0)
    {
        r = 255; g = 130; b = 0;   // Warning orange
    }
    else
    {
        r = 255; g = 205; b = 30;  // CS gold/amber
    }
}

beepBars(Float:remain, out[], len)
{
    if (!get_pcvar_num(g_pBeep))
    {
        out[0] = 0
        return
    }

    new filled
    if (remain <= 5.0)       filled = 5
    else if (remain <= 10.0) filled = 4
    else if (remain <= 20.0) filled = 3
    else if (remain <= 30.0) filled = 2
    else                     filled = 1

    new p = 0
    for (new i = 0; i < 5 && p < len - 1; i++)
        out[p++] = (i < filled) ? '|' : '.'

    out[p] = 0
}

defuseNote(id, Float:remain, out[], len)
{
    new Float:need = cs_get_user_defuse(id) ? DEFUSE_KIT : DEFUSE_NOKIT

    if (remain >= need)
        formatex(out, len, "Defusable: %.0fs", need)
    else
        formatex(out, len, "TOO LATE!")
}

stock hide_timer(id, bool:hide)
{
    if (!is_user_connected(id))
        return

    message_begin(MSG_ONE_UNRELIABLE, g_msgHideWeapon, _, id)
    write_byte(hide ? HIDEHUD_TIMER : 0)
    message_end()
}
