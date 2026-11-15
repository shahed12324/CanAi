"""PyInstaller entry point (absolute import: a frozen __main__ inside the package cannot use relative imports)."""
import sys

from jarvis_agent.cli import main

if __name__ == "__main__":
    sys.exit(main())
