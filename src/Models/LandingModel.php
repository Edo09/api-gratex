<?php
require_once(__DIR__ . '/../Database.php');

class LandingModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /**
     * Get all carousel items
     * @return array
     */
    public function getCarouselItems()
    {
        $sql = "SELECT * FROM landing_carousel ORDER BY id ASC";
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Add new carousel item
     * @param string $title
     * @param string $subtitle
     * @param string $image_path
     * @return array
     */
    public function addCarouselItem($title, $subtitle, $image_path)
    {
        try {
            $sql = "INSERT INTO landing_carousel (title, subtitle, image_path, created_at) VALUES (:title, :subtitle, :image_path, NOW())";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':title' => $title,
                ':subtitle' => $subtitle,
                ':image_path' => $image_path
            ]);
            return ['success', 'Item added successfully', $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            return ['error', $e->getMessage()];
        }
    }

    /**
     * Delete carousel item
     * @param int $id
     * @return array
     */
    public function deleteCarouselItem($id)
    {
        try {
            // First get image path to delete file later
            $sql = "SELECT image_path FROM landing_carousel WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            $item = $stmt->fetch();

            if (!$item) {
                return ['error', 'Item not found'];
            }

            $sql = "DELETE FROM landing_carousel WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);

            return ['success', 'Item deleted successfully', $item['image_path']];
        } catch (PDOException $e) {
            return ['error', $e->getMessage()];
        }
    }

    /**
     * Get all services items
     * @return array
     */
    public function getServices()
    {
        $sql = "SELECT * FROM landing_services ORDER BY id DESC";
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Add new service item
     * @param string $title
     * @param string $description
     * @param string $image_path
     * @return array
     */
    public function addService($title, $description, $image_path)
    {
        try {
            $sql = "INSERT INTO landing_services (title, description, image_path, created_at) VALUES (:title, :description, :image_path, NOW())";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':image_path' => $image_path
            ]);
            return ['success', 'Service added successfully', $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            return ['error', $e->getMessage()];
        }
    }

    /**
     * Delete service item
     * @param int $id
     * @return array
     */
    public function deleteService($id)
    {
        try {
            // First get image path to delete file later
            $sql = "SELECT image_path FROM landing_services WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            $item = $stmt->fetch();

            if (!$item) {
                return ['error', 'Item not found'];
            }

            $sql = "DELETE FROM landing_services WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);

            return ['success', 'Service deleted successfully', $item['image_path']];
        } catch (PDOException $e) {
            return ['error', $e->getMessage()];
        }
    }
}
