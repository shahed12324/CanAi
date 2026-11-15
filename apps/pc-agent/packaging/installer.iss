; Inno Setup script (per-user install, no admin, no service, no auto-start unless the person ticks it).  NOT COMPILED by the authors - needs Windows + Inno Setup 6.
[Setup]
AppName=CAN AI Agent
AppVersion=1.0.0
DefaultDirName={localappdata}\CanAiAgent
PrivilegesRequired=lowest
OutputBaseFilename=CanAiAgentSetup
DisableProgramGroupPage=yes
[Files]
Source: "..\dist\jarvis-agent.exe"; DestDir: "{app}"; Flags: ignoreversion
[Tasks]
Name: "autostart"; Description: "Start the agent window when I sign in"; Flags: unchecked
[Icons]
Name: "{userprograms}\CAN AI Agent"; Filename: "{app}\jarvis-agent.exe"; Parameters: "gui"
Name: "{userstartup}\CAN AI Agent"; Filename: "{app}\jarvis-agent.exe"; Parameters: "gui"; Tasks: autostart
[Run]
Filename: "{app}\jarvis-agent.exe"; Parameters: "gui"; Description: "Open CAN AI Agent"; Flags: postinstall nowait skipifsilent
