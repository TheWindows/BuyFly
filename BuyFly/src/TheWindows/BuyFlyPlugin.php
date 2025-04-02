<?php
namespace TheWindows;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\form\Form;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\scheduler\Task;
use onebone\economyapi\EconomyAPI;
use pocketmine\utils\Config;

class BuyFlyPlugin extends PluginBase implements Listener {

	private array $remainingTime = [];
	private array $flySessions = [];
	private Config $data;
	private array $flyingPlayers = [];
	private int $pricePerMinute = 1000;

	public function onEnable(): void {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getLogger()->info("
  ____                _____ _       
 | __ ) _   _ _   _  |  ___| |_   _ 
 |  _ \| | | | | | | | |_  | | | | |
 | |_) | |_| | |_| | |  _| | | |_| |
 |____/ \__,_|\__, | |_|   |_|\__, |
              |___/           |___/ 
              Made By TheWindows ( Version 1.0.1 )
");

		$this->getServer()->getCommandMap()->register("bfly", new class($this) extends Command {
			private BuyFlyPlugin $plugin;

			public function __construct(BuyFlyPlugin $plugin) {
				parent::__construct("bfly", "Flight time commands", "/bfly [menu|time|help|info|admin|all]");
				$this->setPermission("buyfly.use");
				$this->plugin = $plugin;
			}

			public function execute(CommandSender $sender, string $label, array $args): bool {
				if(!$sender instanceof Player) {
					$sender->sendMessage("§cThis command can only be used in-game!");
					return false;
				}

				$subcommand = strtolower($args[0] ?? "help");
				switch($subcommand) {
					case "menu":
						$this->plugin->showBuyForm($sender);
						break;
					case "time":
						$this->plugin->showTimeInfo($sender);
						break;
					case "help":
						$this->plugin->showHelp($sender);
						break;
					case "info":
						$this->plugin->showInfo($sender);
						break;
					case "admin":
						if($sender->hasPermission("buyfly.admin")) {
							$this->plugin->showAdminForm($sender);
						} else {
							$sender->sendMessage("§cYou don't have permission to use this command!");
						}
						break;
					case "all":
						if($sender->hasPermission("buyfly.admin")) {
							$this->plugin->showAllForm($sender);
						} else {
							$sender->sendMessage("§cYou don't have permission to use this command!");
						}
						break;
					default:
						$this->plugin->showHelp($sender);
						break;
				}
				return true;
			}
		});

		$this->getServer()->getCommandMap()->register("tfly", new class($this) extends Command {
			private BuyFlyPlugin $plugin;

			public function __construct(BuyFlyPlugin $plugin) {
				parent::__construct("tfly", "Toggle flight mode", "/tfly");
				$this->setPermission("togglefly.use");
				$this->plugin = $plugin;
			}

			public function execute(CommandSender $sender, string $label, array $args): bool {
				if(!$sender instanceof Player) {
					$sender->sendMessage("§cThis command can only be used in-game!");
					return false;
				}
				$this->plugin->toggleFlight($sender);
				return true;
			}
		});

		$this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
		$this->remainingTime = $this->data->getAll();
		$this->pricePerMinute = $this->getConfig()->get("price-per-minute", 1000);
	}

	public function onDisable(): void {
		foreach($this->remainingTime as $name => $time) {
			$this->data->set($name, $time);
		}
		$this->data->save();

		foreach($this->flyingPlayers as $name => $_) {
			if(($player = $this->getServer()->getPlayerExact($name)) !== null) {
				$this->disableFlight($player);
			}
		}
	}

	public function showHelp(Player $player): void {
		$message = "§6-----------------------\n";
		$message .= "§e/tfly §f- for enabling and disabling the fly\n";
		$message .= "§e/bfly menu §f- for buying time fly\n";
		$message .= "§e/bfly time §f- for checking the fly time status\n";
		$message .= "§e/bfly help §f- for bfly commands\n";
		$message .= "§e/bfly info §f- plugin information\n";
		if($player->hasPermission("buyfly.admin")) {
			$message .= "§e/bfly admin §f- admin settings\n";
			$message .= "§e/bfly all §f- give all players flight\n";
		}
		$message .= "§6-----------------------";
		$player->sendMessage($message);
	}

	public function showInfo(Player $player): void {
		$message = "§6-----------------------\n";
		$message .= "§eVersion: §f1.0.1\n";
		$message .= "§eCreator: §fTheWindows\n";
		$message .= "§6-----------------------";
		$player->sendMessage($message);
	}

	public function onJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$name = $player->getName();

		if(isset($this->remainingTime[$name]) && $this->remainingTime[$name] > 0) {
			$player->sendMessage("§aYou have §e" . $this->formatTime($this->remainingTime[$name]) . " §aof remaining flight time!");
			$player->sendMessage("§aUse §e/tfly §ato enable flight mode!");
		}
	}

	public function onQuit(PlayerQuitEvent $event): void {
		$player = $event->getPlayer();
		$name = $player->getName();

		if(isset($this->flyingPlayers[$name])) {
			$this->disableFlight($player, false);
		}

		$this->data->set($name, $this->remainingTime[$name] ?? 0);
		$this->data->save();
	}

	public function showTimeInfo(Player $player): void {
		$name = $player->getName();
		$remaining = $this->getRemainingTime($player);
		$status = isset($this->flyingPlayers[$name]) ? "§aOn" : "§cOff";

		$message = "§6-----------------------\n";
		$message .= "§eTime Left: §f" . $this->formatTime($remaining) . "\n";
		$message .= "§eCost Per Minute: §f" . $this->pricePerMinute . "$\n";
		$message .= "§eFly Status: " . $status . "\n";
		$message .= "§6-----------------------";

		$player->sendMessage($message);
	}

	public function showBuyForm(Player $player): void {
		$form = new class($this) implements Form {
			public function __construct(private BuyFlyPlugin $plugin) {}

			public function handleResponse(Player $player, $data): void {
				if($data === null) return;

				try {
					$minutes = (int)($data[0] ?? 0);
					if($minutes < 1) {
						$player->sendMessage("§cYou must enter at least 1 minute!");
						return;
					}

					$economy = $this->plugin->getEconomyAPI();
					if(!$economy instanceof EconomyAPI) {
						$player->sendMessage("§cEconomy system error!");
						return;
					}

					$cost = $minutes * $this->plugin->getPricePerMinute();
					$balance = $economy->myMoney($player);

					if($balance < $cost) {
						$player->sendMessage("§cYou need §6$$cost §cbut only have §6$$balance");
						return;
					}

					if(!$economy->reduceMoney($player, $cost)) {
						$player->sendMessage("§cTransaction failed!");
						return;
					}

					$seconds = $minutes * 60;
					$this->plugin->addFlightTime($player, $seconds);
					$player->sendMessage("§aPurchased §e" . $this->plugin->formatTime($seconds) . " §afor §6$$cost");
					$player->sendMessage("§aTotal remaining: §e" . $this->plugin->formatTime($this->plugin->getRemainingTime($player)));
				} catch (\Exception $e) {
					$this->plugin->getLogger()->error("Error: " . $e->getMessage());
					$player->sendMessage("§cAn error occurred!");
				}
			}

			public function jsonSerialize(): array {
				return [
					"type" => "custom_form",
					"title" => "§l§bBuy Flight",
					"content" => [
						[
							"type" => "input",
							"text" => "§7Enter minutes ",
							"default" => "0",
							"placeholder" => "Enter A Minimum Time ( Example : 1 )"
						]
					]
				];
			}
		};

		$player->sendForm($form);
	}

	public function showAdminForm(Player $player): void {
		$form = new class($this) implements Form {
			public function __construct(private BuyFlyPlugin $plugin) {}

			public function handleResponse(Player $player, $data): void {
				if($data === null) return;

				try {
					$newPrice = (int)($data[0] ?? 0);
					if($newPrice < 0) {
						$player->sendMessage("§cPrice cannot be negative!");
						return;
					}

					$this->plugin->setPricePerMinute($newPrice);
					$player->sendMessage("§aFlight price per minute updated to §e" . $newPrice . "$");
				} catch (\Exception $e) {
					$this->plugin->getLogger()->error("Error: " . $e->getMessage());
					$player->sendMessage("§cAn error occurred!");
				}
			}

			public function jsonSerialize(): array {
				return [
					"type" => "custom_form",
					"title" => "§l§bFlight Admin Panel",
					"content" => [
						[
							"type" => "input",
							"text" => "§7New price per minute",
							"default" => (string)$this->plugin->getPricePerMinute(),
							"placeholder" => "Enter amount ( Example : 100$ )"
						]
					]
				];
			}
		};

		$player->sendForm($form);
	}

	public function showAllForm(Player $player): void {
		$form = new class($this) implements Form {
			public function __construct(private BuyFlyPlugin $plugin) {}

			public function handleResponse(Player $player, $data): void {
				if($data === null) return;

				try {
					$minutes = (int)($data[0] ?? 0);
					if($minutes < 1) {
						$player->sendMessage("§cYou must enter at least 1 minute!");
						return;
					}

					$seconds = $minutes * 60;
					$onlinePlayers = $this->plugin->getServer()->getOnlinePlayers();
					$count = count($onlinePlayers);

					foreach($onlinePlayers as $onlinePlayer) {
						$this->plugin->addFlightTime($onlinePlayer, $seconds);
						if(!isset($this->plugin->flyingPlayers[$onlinePlayer->getName()])) {
							$this->plugin->enableFlight($onlinePlayer);
						}
						$onlinePlayer->sendMessage("§aYou received §e" . $this->plugin->formatTime($seconds) . " §aof flight time from an admin!");
					}

					$player->sendMessage("§aGranted §e" . $this->plugin->formatTime($seconds) . " §ato §6" . $count . " §aonline players!");
				} catch (\Exception $e) {
					$this->plugin->getLogger()->error("Error: " . $e->getMessage());
					$player->sendMessage("§cAn error occurred!");
				}
			}

			public function jsonSerialize(): array {
				return [
					"type" => "custom_form",
					"title" => "§l§bFlight All",
					"content" => [
						[
							"type" => "input",
							"text" => "§7Minutes to give all players",
							"default" => "0",
							"placeholder" => "Enter A Minimum Time ( Example : 1 )"
						]
					]
				];
			}
		};

		$player->sendForm($form);
	}

	public function addFlightTime(Player $player, int $seconds): void {
		$name = $player->getName();
		$this->remainingTime[$name] = ($this->remainingTime[$name] ?? 0) + $seconds;
		$this->data->set($name, $this->remainingTime[$name]);
		$this->data->save();
	}

	public function getRemainingTime(Player $player): int {
		return $this->remainingTime[$player->getName()] ?? 0;
	}

	public function toggleFlight(Player $player): void {
		$name = $player->getName();

		if(isset($this->flyingPlayers[$name])) {
			$this->disableFlight($player);
			$player->sendMessage("§aFlight disabled. Remaining: §e" . $this->formatTime($this->remainingTime[$name]));
		} else {
			$remaining = $this->getRemainingTime($player);
			if($remaining > 0) {
				$this->enableFlight($player);
				$player->sendMessage("§aFlight enabled! Time left: §e" . $this->formatTime($remaining));
			} else {
				$player->sendMessage("§cNo flight time remaining!");
				$player->sendMessage("§cBuy more with §e/bfly menu");
			}
		}
	}

	public function enableFlight(Player $player): void {
		$name = $player->getName();
		$this->flyingPlayers[$name] = true;
		$player->setAllowFlight(true);
		$player->setFlying(true);
		$this->startFlyTimer($player);
	}

	public function disableFlight(Player $player, bool $save = true): void {
		$name = $player->getName();
		if(isset($this->flyingPlayers[$name])) {
			unset($this->flyingPlayers[$name]);
			$player->setFlying(false);
			$player->setAllowFlight(false);
			$this->stopFlyTimer($name);

			if($save) {
				$this->data->set($name, $this->remainingTime[$name] ?? 0);
				$this->data->save();
			}
		}
	}

	private function startFlyTimer(Player $player): void {
		$name = $player->getName();
		if(isset($this->flySessions[$name])) return;

		$this->flySessions[$name] = $this->getScheduler()->scheduleRepeatingTask(
			new class($this, $player) extends Task {
				public function __construct(
					private BuyFlyPlugin $plugin,
					private Player $player
				) {}

				public function onRun(): void {
					$name = $this->player->getName();
					$remaining = $this->plugin->getRemainingTime($this->player);

					if($remaining <= 0 || !$this->player->isOnline()) {
						$this->plugin->disableFlight($this->player);
						return;
					}

					$this->plugin->decrementRemainingTime($name);

					if($remaining === 10) {
						$this->player->sendPopup("§c10 seconds remaining!");
					} elseif($remaining <= 5 && $remaining > 0) {
						$this->player->sendPopup("§c" . $remaining . " second" . ($remaining !== 1 ? "s" : "") . " remaining!");
					}

					if($remaining === 0) {
						$this->player->sendMessage("§cFlight time expired!");
						$this->plugin->disableFlight($this->player);
					}
				}
			},
			20
		);
	}

	public function decrementRemainingTime(string $playerName): void {
		if(isset($this->remainingTime[$playerName])) {
			$this->remainingTime[$playerName]--;
		}
	}

	private function stopFlyTimer(string $name): void {
		if(isset($this->flySessions[$name])) {
			$this->flySessions[$name]->cancel();
			unset($this->flySessions[$name]);
		}
	}

	public function formatTime(int $seconds): string {
		$minutes = floor($seconds / 60);
		$seconds = $seconds % 60;
		return $minutes . " minutes " . $seconds . " seconds";
	}

	public function getPricePerMinute(): int {
		return $this->pricePerMinute;
	}

	public function setPricePerMinute(int $price): void {
		$this->pricePerMinute = $price;
		$this->getConfig()->set("price-per-min", $price);
		$this->getConfig()->save();
	}

	public function getEconomyAPI(): ?EconomyAPI {
		return $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
	}
}