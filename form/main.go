package main

import (
	"fmt"
	"log"
	"net/http"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"

	"form/configs"
	"form/services"
)

type TaskStatus struct {
	ID        string                `json:"task_id"`
	Type      string                `json:"type"`   // fill, register, quick_fill
	Status    string                `json:"status"` // processing, completed, failed
	Result    *services.BatchResult `json:"result,omitempty"`
	CreatedAt time.Time             `json:"created_at"`
}

type FillRequest struct {
	PhoneNumber string `json:"phone_number" binding:"required"`
	FullName    string `json:"full_name" binding:"required"`
}

type RegisterRequest struct {
	PhoneNumber string `json:"phone_number" binding:"required"`
	FullName    string `json:"full_name" binding:"required"`
	Email       string `json:"email" binding:"required"`
}

var (
	globalFiller *services.AutoFormFiller
	tasks        = make(map[string]*TaskStatus)
	tasksMu      sync.RWMutex
)

func main() {
	var err error
	globalFiller, err = services.NewAutoFormFiller(
		services.WithDebug(true),
	)
	if err != nil {
		log.Fatalf("❌ خطا در راه‌اندازی مرورگر سرور: %v", err)
	}
	defer globalFiller.Close()

	gin.SetMode(gin.ReleaseMode)
	r := gin.Default()

	r.POST("/api/fill", handleBatchFill)
	r.POST("/api/register", handleBatchRegister)
	r.GET("/api/tasks/:id", handleGetTaskStatus)

	fmt.Println("🚀 Web Server is running on http://localhost:8084")
	if err := r.Run(":8084"); err != nil {
		log.Fatalf("❌ خطا در اجرای سرور: %v", err)
	}
}

func handleBatchFill(c *gin.Context) {
	var req FillRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "فیلدهای phone_number و full_name الزامی هستند"})
		return
	}

	fillSites := configs.LoadFillSites()
	if len(fillSites) == 0 {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": "هیچ سایتی برای پر کردن فرم در کانفیگ تعریف نشده است"})
		return
	}

	taskID := uuid.New().String()
	createTask(taskID, "fill")

	go func(id, phone, name string, sites []string) {
		result := globalFiller.BatchSubmit(sites, phone, name)
		updateTaskResult(id, result)
	}(taskID, req.PhoneNumber, req.FullName, fillSites)

	c.JSON(http.StatusAccepted, gin.H{
		"message": "عملیات Fill آغاز شد",
		"task_id": taskID,
	})
}

func handleBatchRegister(c *gin.Context) {
	var req RegisterRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "اطلاعات ارسالی ناقص است (نام، شماره، ایمیل)"})
		return
	}

	registerSites := configs.LoadRegisterSites()
	if len(registerSites) == 0 {
		c.JSON(http.StatusUnprocessableEntity, gin.H{"error": "هیچ سایتی برای ثبت‌نام در کانفیگ تعریف نشده است"})
		return
	}

	taskID := uuid.New().String()
	createTask(taskID, "register")

	go func(id, phone, name, email string, sites []string) {
		result := globalFiller.BatchRegister(sites, phone, name, email)
		updateTaskResult(id, result)
	}(taskID, req.PhoneNumber, req.FullName, req.Email, registerSites)

	c.JSON(http.StatusAccepted, gin.H{
		"message": "عملیات Register آغاز شد",
		"task_id": taskID,
	})
}

func handleGetTaskStatus(c *gin.Context) {
	taskID := c.Param("id")

	tasksMu.RLock()
	task, exists := tasks[taskID]
	tasksMu.RUnlock()

	if !exists {
		c.JSON(http.StatusNotFound, gin.H{"error": "تسک مورد نظر یافت نشد"})
		return
	}

	c.JSON(http.StatusOK, task)
}

func createTask(id, taskType string) {
	tasksMu.Lock()
	defer tasksMu.Unlock()
	tasks[id] = &TaskStatus{
		ID:        id,
		Type:      taskType,
		Status:    "processing",
		CreatedAt: time.Now(),
	}
}

func updateTaskResult(id string, result *services.BatchResult) {
	tasksMu.Lock()
	defer tasksMu.Unlock()
	if task, exists := tasks[id]; exists {
		task.Status = "completed"
		task.Result = result

		fmt.Printf("\n✅ Task [%s] Completed -> Success: %d | Failed: %d | Duration: %s\n",
			task.Type, result.Success, result.Failed, result.Duration)
		if len(result.Errors) > 0 {
			fmt.Println("❌ Errors:")
			for _, e := range result.Errors {
				fmt.Printf("  - %s\n", e)
			}
		}
	}
}
