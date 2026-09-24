#include <amxmodx>
#include <fakemeta>
#include <hamsandwich>

#define PLUGIN  "GameLand Fast-Duck & View Shake Fixer"
#define VERSION "1.0.0"
#define AUTHOR  "GAMELAND"

#define MAX_PLAYERS 32

// CS 1.6 Player Duck Offsets (CBasePlayer)
// m_flDuckTime = 363 (linux diff +5 = 368)
// m_bInDuck = 364
// m_flTimeStepSound = 366
#define OFFSET_DUCKTIME     363
#define EXTRAOFFSET_PLAYER  5

new g_pcvar_enable
new g_pcvar_smooth_stand
new g_pcvar_debug

new Float:g_lastDuckRelease[MAX_PLAYERS + 1]

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)
    register_cvar("gameland_fastduck_fix_version", VERSION, FCVAR_SERVER | FCVAR_SPONLY)

    g_pcvar_enable = register_cvar("gl_fastduck_fix", "1")
    g_pcvar_smooth_stand = register_cvar("gl_fastduck_smooth", "1")
    g_pcvar_debug = register_cvar("gl_fastduck_debug", "0")

    RegisterHam(Ham_Player_PreThink, "player", "ham_player_prethink", 0)
    RegisterHam(Ham_Player_PostThink, "player", "ham_player_postthink", 1)
}

public client_connect(id)
{
    g_lastDuckRelease[id] = 0.0
}

public ham_player_prethink(id)
{
    if (!get_pcvar_num(g_pcvar_enable) || !is_user_alive(id) || is_user_bot(id))
        return HAM_IGNORED

    new button = pev(id, pev_button)
    new oldbuttons = pev(id, pev_oldbuttons)
    new flags = pev(id, pev_flags)

    // Player just released duck key while on ground
    if ((oldbuttons & IN_DUCK) && !(button & IN_DUCK) && (flags & FL_ONGROUND))
    {
        // When tapping duck (Fast Duck / Double Duck), the engine introduces a 1000ms delay penalty in m_flDuckTime
        // which creates the unnatural view stuttering / camera jerking.
        new Float:ducktime = get_pdata_float(id, OFFSET_DUCKTIME, EXTRAOFFSET_PLAYER)
        if (ducktime > 0.0)
        {
            // Reset the penalty immediately to keep the movement fluid and synchronized with hitbox
            set_pdata_float(id, OFFSET_DUCKTIME, 0.0, EXTRAOFFSET_PLAYER)
            g_lastDuckRelease[id] = get_gametime()

            if (get_pcvar_num(g_pcvar_debug))
                server_print("[GL DUCK] Cleared duck penalty for player %d", id)
        }
    }

    return HAM_IGNORED
}

public ham_player_postthink(id)
{
    if (!get_pcvar_num(g_pcvar_enable) || !get_pcvar_num(g_pcvar_smooth_stand) || !is_user_alive(id) || is_user_bot(id))
        return HAM_IGNORED

    // If fast duck was recently tapped, ensure view_ofs is smoothly restored to standing eye-level (12.0 for CS standard)
    if (g_lastDuckRelease[id] > 0.0 && (get_gametime() - g_lastDuckRelease[id]) < 0.15)
    {
        new flags = pev(id, pev_flags)
        if (!(flags & FL_DUCKING))
        {
            new Float:view_ofs[3]
            pev(id, pev_view_ofs, view_ofs)
            if (view_ofs[2] < 12.0 && view_ofs[2] > 0.0)
            {
                view_ofs[2] = 12.0
                set_pev(id, pev_view_ofs, view_ofs)
            }
        }
    }

    return HAM_IGNORED
}
